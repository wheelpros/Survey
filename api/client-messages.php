<?php

/*
|--------------------------------------------------------------------------
| Messages for a client: their account's chat with its account managers
|--------------------------------------------------------------------------
|
| Backs messages.html and the client sidebar badge (assets/messages-badge.js).
|
|   GET  ?action=thread[&after=|before=]   the chat, its account managers, who has read what
|   GET  ?action=count                     my unread total and newest incoming id, for the badge
|   POST {"action":"send","body":"..."}
|   POST {"action":"read"}
|
| A client on a browser session only, and only their own account's chat - the
| account comes from the session, never from the request. Staff open the same
| chat from admin-messages.php; a client's AI connection through
| api/v1/me/messages.php. The rules are client-message-writes.php's.
|
| Like the staff page, the page polls ?action=thread&after= every few seconds
| while it is open. Every count check may also run the email sweep for
| messages left unread (runClientMessageSweep()), after the reply has gone.
|
*/

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

require_once "db.php";
require_once "auth.php";
require_once "client-message-writes.php";

function reply($success, $message = "", $extra = [], $status = 200)
{
    http_response_code($status);
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$p = currentPrincipal($pdo);

if (!$p) {
    reply(false, "Unauthorized token", [], 401);
}

if ($p["kind"] !== "user" || $p["via"] !== "session") {
    reply(false, "This is the client's Messages page.", [], 403);
}

// DDL commits implicitly, so before anything else.
ensureAdminColumns($pdo);
ensureClientMessageTables($pdo);

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$action = $_GET["action"] ?? $input["action"] ?? "";

try {
    $account = openClientChat($pdo, $p);
} catch (PortalWriteError $e) {
    reply(false, $e->getMessage(), [], $e->status);
}
$accountId = (int) $account["id"];

if ($action === "count") {
    runClientMessageSweep($pdo);
    reply(true, "", [
        "unread" => clientMessageUnreadCount($pdo, $p),
        "latest_id" => clientMessageLatestIncoming($pdo, $p),
    ]);
}

if ($action === "thread") {
    $managers = clientChatManagers($pdo, $accountId);
    $director = $managers ? null : clientChatDirector($pdo);
    [$rows, $hasOlder] = clientChatMessages($pdo, $accountId, (int) ($_GET["after"] ?? 0), (int) ($_GET["before"] ?? 0));

    reply(true, "", [
        "managers" => array_map("clientChatManagerRow", $managers),
        // No account manager yet: the Director answers until one is assigned.
        "director" => $director ? clientChatManagerRow($director) : null,
        "can_send" => $managers || $director,
        "messages" => array_map(function ($m) use ($p) {
            return clientMessageRow($m, $p);
        }, $rows),
        "has_older" => $hasOlder,
        "seen_by" => clientChatSeenBy($pdo, $p, $accountId),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(false, "Unknown action", [], 400);
}

if ($action === "read") {
    markClientChatRead($pdo, $p, $account);
    reply(true, "", ["unread" => clientMessageUnreadCount($pdo, $p)]);
}

if ($action === "send") {
    try {
        $row = sendClientMessage($pdo, $p, $account, $input["body"] ?? "");
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage(), [], $e->status);
    }
    reply(true, "", ["message_row" => clientMessageRow($row, $p)]);
}

reply(false, "Unknown action", [], 400);
