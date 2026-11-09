<?php

/*
|--------------------------------------------------------------------------
| The signed-in client's own team - the Team section of profile.html
|--------------------------------------------------------------------------
|
|   GET                                       the people, and the owner's invites
|   POST {action: invite, email}              owner only, as are the rest
|   POST {action: resend, id}
|   POST {action: revoke, id}
|   POST {action: remove, id}
|
*/

require_once "auth.php";
require_once "client-team-writes.php";
require_once "rate-limit.php";

header("Content-Type: application/json; charset=UTF-8");

function teamReply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// A database error is our fault, not the person's connection, so it gets a real
// reply: the page would otherwise fail to read one and blame their connection.
set_exception_handler(function (Throwable $e) {
    error_log("my-team: " . $e->getMessage());
    teamReply(false, "Something went wrong on our side, so that didn't go through. Please try again.", [], 500);
});

$user = clientFromSessionToken($pdo, bearerToken());

if (!$user) {
    teamReply(false, "Unauthorized", [], 401);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    teamReply(true, "", ["team" => clientTeamSnapshot($pdo, $user)]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    teamReply(false, "Unsupported request method.", [], 405);
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$action = (string) ($input["action"] ?? "");
$id = (int) ($input["id"] ?? 0);

try {
    switch ($action) {
        case "invite":
        case "resend":
            // Each sends an email to an address the caller typed.
            requireAccountOwner($user);
            if (rateLimited($pdo, "client-invite:" . (int) $user["id"], 20, 20, 3600)) {
                teamReply(false, "That's a lot of invites at once. Try again in a while.", [], 429);
            }

            $sent = $action === "invite"
                ? inviteTeammate($pdo, $user, $input["email"] ?? "")
                : resendClientInvite($pdo, $user, $id);

            $failed = $action === "resend"
                ? "The email to " . $sent["email"] . " couldn't be sent, so any earlier link still stands. Try again in a while."
                : "The invite is saved, but the email to " . $sent["email"] . " couldn't be sent. Try resending it.";

            teamReply(true, $sent["emailed"] ? "Invite sent to " . $sent["email"] . "." : $failed, [
                "emailed" => $sent["emailed"],
                "team" => clientTeamSnapshot($pdo, $user),
            ]);

        case "revoke":
            revokeClientInvite($pdo, $user, $id);
            teamReply(true, "Invite revoked.", ["team" => clientTeamSnapshot($pdo, $user)]);

        case "remove":
            $person = removeTeammate($pdo, $user, $id);
            teamReply(true, $person["name"] . " is no longer on your team.", ["team" => clientTeamSnapshot($pdo, $user)]);
    }
} catch (PortalWriteError $e) {
    teamReply(false, $e->getMessage(), [], $e->status);
}

teamReply(false, "Unknown action.", [], 400);
