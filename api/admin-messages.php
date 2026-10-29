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
|   GET  ?action=count                         my unread total, for the badge
|   GET  ?action=people                        who I can start a chat with
|   POST {"action":"send","to":7,"body":"..."}
|   POST {"action":"read","with":7}
|
| Private: every lookup is scoped to a conversation the caller is one of the
| two people in. Anyone else - the Director included - gets "not found", so
| nothing confirms that a conversation exists. Admins on a browser session
| only: clients and AI tokens are refused, and nothing here is logged to the
| event log or the notifications inbox.
|
| The page polls ?action=thread&after= every few seconds while it is open, and
| ?action=list a little less often (to chime for new messages) - there are no
| websockets or background workers on this host.
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

const MESSAGE_MAX_CHARS = 2000;
const MESSAGE_PAGE_SIZE = 50;

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

/** The other admin as the page shows them; "removed" when the row is gone. */
function otherAdmin(PDO $pdo, $id)
{
    $stmt = $pdo->prepare("SELECT id, name, role, position, avatar_path, active FROM admins WHERE id = ?");
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ["id" => (int) $id, "name" => "Removed admin", "role" => "", "position" => "",
                "avatar_path" => null, "active" => false, "removed" => true];
    }

    return [
        "id"          => (int) $row["id"],
        "name"        => (string) $row["name"],
        "role"        => (string) $row["role"],
        "position"    => (string) ($row["position"] ?? ""),
        "avatar_path" => $row["avatar_path"] ?: null,
        "active"      => !isset($row["active"]) || (int) $row["active"] === 1,
        "removed"     => false,
    ];
}

/** The pair's conversation row, or null. Smaller id first, as stored. */
function findConversation(PDO $pdo, $a, $b)
{
    $stmt = $pdo->prepare("SELECT * FROM admin_conversations WHERE admin_low = ? AND admin_high = ?");
    $stmt->execute([min($a, $b), max($a, $b)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Which read-marker column is mine in this conversation. */
function myReadColumn(array $conversation, $me)
{
    return (int) $conversation["admin_low"] === $me ? "low_last_read_id" : "high_last_read_id";
}

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
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM admin_conversations c
        JOIN admin_messages m ON m.conversation_id = c.id
        WHERE (c.admin_low = :me1 AND m.id > c.low_last_read_id AND m.sender_id <> :me2)
           OR (c.admin_high = :me3 AND m.id > c.high_last_read_id AND m.sender_id <> :me4)
    ");
    $stmt->execute(["me1" => $me, "me2" => $me, "me3" => $me, "me4" => $me]);
    reply(true, "", ["unread" => (int) $stmt->fetchColumn()]);
}

if ($action === "people") {
    $stmt = $pdo->prepare("
        SELECT id, name, role, position, avatar_path
        FROM admins
        WHERE id <> ? AND (active IS NULL OR active = 1)
        ORDER BY name
    ");
    $stmt->execute([$me]);
    reply(true, "", ["people" => array_map(function ($a) {
        return [
            "id" => (int) $a["id"],
            "name" => (string) $a["name"],
            "role" => (string) $a["role"],
            "position" => (string) ($a["position"] ?? ""),
            "avatar_path" => $a["avatar_path"] ?: null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC))]);
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
            "with"     => otherAdmin($pdo, $c["other_id"]),
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
    $other = otherAdmin($pdo, $otherId);
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

    $theirRead = (int) $conversation[(int) $conversation["admin_low"] === $me ? "high_last_read_id" : "low_last_read_id"];

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
    $conversation = findConversation($pdo, $me, $otherId);

    if (!$conversation) {
        reply(true, "", ["unread" => 0]);
    }

    $column = myReadColumn($conversation, $me);
    $pdo->prepare("UPDATE admin_conversations SET $column = last_message_id WHERE id = ?")
        ->execute([(int) $conversation["id"]]);

    reply(true);
}

if ($action === "send") {
    $otherId = otherId($input["to"] ?? 0, $me);
    $body = trim(str_replace("\r\n", "\n", (string) ($input["body"] ?? "")));

    if ($body === "") {
        reply(false, "Write a message first.", [], 400);
    }
    if (mb_strlen($body) > MESSAGE_MAX_CHARS) {
        reply(false, "A message can be up to " . MESSAGE_MAX_CHARS . " characters.", [], 400);
    }

    $other = otherAdmin($pdo, $otherId);
    if ($other["removed"]) {
        reply(false, "That person isn't on the team.", [], 404);
    }
    if (!$other["active"]) {
        reply(false, $other["name"] . "'s account is switched off, so they can't receive messages.", [], 400);
    }

    require_once "rate-limit.php";
    if (rateLimited($pdo, "admin-message:" . $me, 30, 30, 60)) {
        reply(false, "You're sending messages too quickly. Wait a minute and try again.", [], 429);
    }

    $low = min($me, $otherId);
    $high = max($me, $otherId);

    try {
        $pdo->beginTransaction();

        // One row per pair, whoever writes first.
        $pdo->prepare("
            INSERT INTO admin_conversations (admin_low, admin_high) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE id = id
        ")->execute([$low, $high]);

        $stmt = $pdo->prepare("SELECT * FROM admin_conversations WHERE admin_low = ? AND admin_high = ? FOR UPDATE");
        $stmt->execute([$low, $high]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("INSERT INTO admin_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)")
            ->execute([(int) $conversation["id"], $me, $body]);
        $messageId = (int) $pdo->lastInsertId();

        // Sending means I've read everything up to my own message.
        $column = myReadColumn($conversation, $me);
        $pdo->prepare("UPDATE admin_conversations SET last_message_id = ?, $column = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$messageId, $messageId, (int) $conversation["id"]]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        reply(false, "Could not send the message.", [], 500);
    }

    $stmt = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM admin_messages WHERE id = ?");
    $stmt->execute([$messageId]);

    reply(true, "", ["message_row" => messageRow($stmt->fetch(PDO::FETCH_ASSOC), $me)]);
}

reply(false, "Unknown action", [], 400);
