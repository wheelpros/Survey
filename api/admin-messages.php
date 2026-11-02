<?php

/*
|--------------------------------------------------------------------------
| Messages: direct chats between admins
|--------------------------------------------------------------------------
|
| Backs admin-messages.html and the sidebar badge (assets/messages-badge.js).
|
|   GET  ?action=list                          my conversations, latest first
|   GET  ?action=thread&with=7[&after=|before=] one conversation's messages
|   GET  ?action=count                         my unread total and newest incoming id, for the badge
|   GET  ?action=people                        who I can start a chat with
|   POST {"action":"send","to":7,"body":"..."}
|   POST {"action":"read","with":7}
|
| Private: every lookup is scoped to a conversation the caller is one of the
| two people in. Anyone else - the Director included - gets "not found", so
| nothing confirms that a conversation exists. Admins on a browser session
| only: clients and AI tokens are refused here - an admin's MCP connection
| reads and sends through api/v1/messages.php instead, with the same checks
| (api/message-writes.php). Nothing here is logged to the event log or the
| notifications inbox.
|
| The page polls ?action=thread&after= every few seconds while it is open, and
| ?action=list a little less often; every other admin page polls ?action=count
| for the sidebar badge and the new message sound. There are no websockets or
| background workers on this host.
|
| Tables: ensureAdminMessageTables() in db.php, sql/admin_messages.sql.
|
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";
require_once "auth.php";
require_once "message-writes.php";

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

if ($p["kind"] !== "admin" || $p["via"] !== "session") {
    reply(false, "Messages are for the team only.", [], 403);
}

// DDL commits implicitly, so before any transaction below.
ensureAdminColumns($pdo);
ensureAdminMessageTables($pdo);

$me = (int) $p["id"];
$input = json_decode(file_get_contents("php://input"), true) ?: [];
$action = $_GET["action"] ?? $input["action"] ?? "";

function messageRow(array $m, $me)
{
    return [
        "id"         => (int) $m["id"],
        "sender_id"  => (int) $m["sender_id"],
        "mine"       => (int) $m["sender_id"] === $me,
        "body"       => (string) $m["body"],
        "created_at" => (string) $m["created_at"],
        "created_ts" => (int) $m["created_ts"],
    ];
}

/** The ?with= / {"with":} admin id, checked. */
function otherId($raw, $me)
{
    $id = (int) $raw;
    if ($id <= 0 || $id === $me) {
        reply(false, "Pick someone to message.", [], 400);
    }
    return $id;
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

if ($action === "count") {
    $unread = messageUnreadCount($pdo, $me);

    // The newest message anyone sent me, read or not - the sidebar compares
    // it between checks to know when to play the new message sound.
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(m.id), 0)
        FROM admin_conversations c
        JOIN admin_messages m ON m.conversation_id = c.id
        WHERE (c.admin_low = :me1 OR c.admin_high = :me2) AND m.sender_id <> :me3
    ");
    $stmt->execute(["me1" => $me, "me2" => $me, "me3" => $me]);

    reply(true, "", ["unread" => $unread, "latest_id" => (int) $stmt->fetchColumn()]);
}

if ($action === "people") {
    reply(true, "", ["people" => messagePeople($pdo, $me)]);
}

if ($action === "list") {
    $stmt = $pdo->prepare("
        SELECT c.*,
               IF(c.admin_low = :me1, c.admin_high, c.admin_low) AS other_id,
               m.body AS last_body, m.sender_id AS last_sender_id,
               UNIX_TIMESTAMP(m.created_at) AS last_ts,
               (SELECT COUNT(*) FROM admin_messages u
                 WHERE u.conversation_id = c.id AND u.sender_id <> :me2
                   AND u.id > IF(c.admin_low = :me3, c.low_last_read_id, c.high_last_read_id)) AS unread
        FROM admin_conversations c
        LEFT JOIN admin_messages m ON m.id = c.last_message_id
        WHERE c.admin_low = :me4 OR c.admin_high = :me5
        ORDER BY c.last_message_id DESC
    ");
    $stmt->execute(["me1" => $me, "me2" => $me, "me3" => $me, "me4" => $me, "me5" => $me]);

    $conversations = array_map(function ($c) use ($pdo, $me) {
        $body = (string) ($c["last_body"] ?? "");
        return [
            "id"       => (int) $c["id"],
            "with"     => messageAdmin($pdo, $c["other_id"]),
            "unread"   => (int) $c["unread"],
            "last"     => $c["last_body"] === null ? null : [
                // The page compares ids to tell a new message from one it has seen.
                "id"      => (int) $c["last_message_id"],
                "mine"    => (int) $c["last_sender_id"] === $me,
                "preview" => mb_strlen($body) > 120 ? mb_substr($body, 0, 119) . "…" : $body,
                "created_ts" => (int) $c["last_ts"],
            ],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    reply(true, "", ["conversations" => $conversations]);
}

if ($action === "thread") {
    $otherId = otherId($_GET["with"] ?? 0, $me);
    $other = messageAdmin($pdo, $otherId);
    $conversation = findConversation($pdo, $me, $otherId);

    // No conversation yet is an empty thread, not an error - the page opens
    // it for a first message. A removed admin with no history is unknown.
    if (!$conversation) {
        if ($other["removed"]) {
            reply(false, "That person isn't on the team.", [], 404);
        }
        reply(true, "", ["with" => $other, "messages" => [], "has_older" => false]);
    }

    $after = (int) ($_GET["after"] ?? 0);
    $before = (int) ($_GET["before"] ?? 0);

    if ($after > 0) {
        // Polling: everything new since the last message on screen, oldest first.
        $stmt = $pdo->prepare("
            SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM admin_messages
            WHERE conversation_id = ? AND id > ?
            ORDER BY id ASC
            LIMIT 200
        ");
        $stmt->execute([(int) $conversation["id"], $after]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasOlder = null;
    } else {
        // The latest page, or the page before $before - newest first, then turned
        // round so the page can append in reading order.
        $stmt = $pdo->prepare("
            SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM admin_messages
            WHERE conversation_id = ?" . ($before > 0 ? " AND id < ?" : "") . "
            ORDER BY id DESC
            LIMIT " . (MESSAGE_PAGE_SIZE + 1)
        );
        $stmt->execute($before > 0 ? [(int) $conversation["id"], $before] : [(int) $conversation["id"]]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasOlder = count($rows) > MESSAGE_PAGE_SIZE;
        $rows = array_reverse(array_slice($rows, 0, MESSAGE_PAGE_SIZE));
    }

    $theirRead = theirReadId($conversation, $me);

    reply(true, "", [
        "with" => $other,
        "messages" => array_map(function ($m) use ($me) {
            return messageRow($m, $me);
        }, $rows),
        "has_older" => $hasOlder,
        // The last of my messages they have seen - the page marks it "Seen".
        "their_last_read_id" => $theirRead,
    ]);
}

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(false, "Unknown action", [], 400);
}

if ($action === "read") {
    $otherId = otherId($input["with"] ?? 0, $me);

    if (!markConversationRead($pdo, $me, $otherId)) {
        reply(true, "", ["unread" => 0]);
    }

    reply(true);
}

if ($action === "send") {
    $otherId = otherId($input["to"] ?? 0, $me);

    try {
        $row = sendMessage($pdo, $me, $otherId, $input["body"] ?? "");
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage(), [], $e->status);
    }

    reply(true, "", ["message_row" => messageRow($row, $me)]);
}

reply(false, "Unknown action", [], 400);
