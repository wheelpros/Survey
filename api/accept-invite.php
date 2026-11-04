<?php

/*
|--------------------------------------------------------------------------
| Joining a client's team - join-team.html
|--------------------------------------------------------------------------
|
| Public: the token from the emailed link is the only credential.
|
|   GET  ?token=                     who the invite is from, and the address
|   POST {token, name, password}     creates the login
|
*/

require_once "client-team-writes.php";
require_once "rate-limit.php";

header("Content-Type: application/json; charset=UTF-8");
header("Referrer-Policy: no-referrer");

function acceptReply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (rateLimited($pdo, "accept-invite", 30, 30, 60)) {
    acceptReply(false, "Too many attempts. Please wait a minute and try again.", [], 429);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $invite = findClientInvite($pdo, $_GET["token"] ?? "");

    if (!$invite) {
        acceptReply(false, CLIENT_INVITE_GONE, [], 410);
    }

    acceptReply(true, "", ["invite" => [
        "email" => $invite["email"],
        "invited_by" => $invite["owner_name"],
        "company" => $invite["company_name"],
    ]]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    acceptReply(false, "Unsupported request method.", [], 405);
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];

try {
    $invite = acceptClientInvite($pdo, $input["token"] ?? "", $input["name"] ?? "", $input["password"] ?? "");
} catch (PortalWriteError $e) {
    acceptReply(false, $e->getMessage(), [], $e->status);
}

acceptReply(true, "Sign in with your email and new password to get started.", ["email" => $invite["email"]]);
