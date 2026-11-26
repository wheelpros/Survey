<?php

/*
| GET  me/messages.php?cursor=&limit=   my_messages
| POST me/messages.php {"read": true}   mark_my_messages_read
|
| The client's own chat with their account managers - messages.html. Newest
| first, page by page, with the account managers. With none assigned yet,
| answered_by_director names the Director, who answers until one is.
| Sending goes through prepare/confirm (send_my_message in ../_writes.php).
|
| The account comes from the token: a client only ever reaches their own
| account's chat. The rules are client-message-writes.php's. Message bodies
| were typed by people, so they come back under untrusted_content.
*/

require_once __DIR__ . "/_me.php";
require_once __DIR__ . "/../../client-message-writes.php";

ensureAdminColumns($pdo);
ensureClientMessageTables($pdo);

try {
    $account = openClientChat($pdo, $p);
} catch (PortalWriteError $e) {
    v1Error($e->status, "not_found", $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    v1RequireScope($p, "self:write");
    $in = json_decode(file_get_contents("php://input"), true);
    if (!is_array($in) || ($in["read"] ?? null) !== true) {
        v1Error(422, "invalid_parameter", "Send read: true.");
    }
    markClientChatRead($pdo, $p, $account);
    v1Reply(200, ["marked" => true, "unread_count" => clientMessageUnreadCount($pdo, $p)]);
}

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

$limit = v1Limit();
$page = v1Page(clientChatNewestFirst($pdo, $account["id"], v1IdCursor(), $limit), $limit, function ($m) {
    return [(int) $m["id"]];
}, function ($m) use ($p) {
    return clientMessageV1Item($m, $p);
});

$managers = clientChatManagers($pdo, $account["id"]);
$director = $managers ? null : clientChatDirector($pdo);
$teamRead = max(array_merge([0], array_column(clientChatSeenBy($pdo, $p, $account["id"]), "last_read_id")));

v1Reply(200, array_merge([
    "account_managers" => array_map(function ($a) {
        return ["id" => (int) $a["id"], "name" => (string) $a["name"], "position" => v1NullableString($a["position"] ?? null)];
    }, $managers),
    "answered_by_director" => $director ? ["id" => (int) $director["id"], "name" => (string) $director["name"]] : null,
    "can_send" => $managers || $director,
], $page, [
    "unread_count" => clientMessageUnreadCount($pdo, $p),
    // The newest message an account manager has seen; yours up to it show as Seen.
    "team_last_read_id" => (int) $teamRead,
]));
