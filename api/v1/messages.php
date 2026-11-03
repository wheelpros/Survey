<?php

/*
| Messages - the caller's own direct chats with other admins.
|
|   GET  messages.php?cursor=&limit=              list_messages
|   GET  messages.php?with=7&cursor=&limit=       read_chat (newest first)
|   GET  messages.php?people=1                    list_message_people
|   POST messages.php {"with": 7}                 mark_chat_read
|
| Sending goes through prepare/confirm (send_message in _writes.php), the
| same as any other change that reaches another person.
|
| Staff only, scope messages:read (messages:write to mark read). The caller
| comes from the token and the other person from ?with=, so a conversation
| is only ever reached by one of its two people - the Director included.
| Message bodies are what colleagues typed: they come back under
| untrusted_content.
|
| Marking read skips prepare/confirm like mark_notifications_read: it only
| moves the caller's own read marker. The other person sees "Seen".
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../message-writes.php";

$p = v1Principal($pdo);
v1RequireStaff($p);

// DDL commits implicitly, so before anything else.
ensureAdminColumns($pdo);
ensureAdminMessageTables($pdo);

$me = (int) $p["id"];

/** The other person as the API shows them. */
function messagePerson(array $a)
{
    return [
        "id" => (int) $a["id"],
        "name" => (string) $a["name"],
        "role" => (string) $a["role"],
        "position" => v1NullableString($a["position"] ?? null),
        "active" => $a["active"] ?? true,
        "removed" => $a["removed"] ?? false,
    ];
}

function messageOtherParam($raw, $me)
{
    $id = (int) $raw;
    if ((string) $raw === "" || !ctype_digit((string) $raw) || $id <= 0) {
        v1Error(422, "invalid_parameter", "with must be an admin id.");
    }
    if ($id === $me) {
        v1Error(422, "invalid_parameter", "That's you - pick someone else.");
    }
    return $id;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    v1RequireScope($p, "messages:write");

    $in = json_decode(file_get_contents("php://input"), true);
    $otherId = messageOtherParam(is_array($in) && is_int($in["with"] ?? null) ? (string) $in["with"] : "", $me);

    if (!markConversationRead($pdo, $me, $otherId)) {
        v1Error(404, "not_found", "You have no conversation with that person.");
    }

    v1Reply(200, ["marked" => true, "unread_count" => messageUnreadCount($pdo, $me)]);
}

v1RequireMethod("GET");
v1RequireScope($p, "messages:read");

if (!empty($_GET["people"])) {
    v1Reply(200, ["items" => array_map(function ($a) {
        return messagePerson($a);
    }, messagePeople($pdo, $me)), "next_cursor" => null]);
}

$limit = v1Limit();
$after = v1IdCursor();

if (isset($_GET["with"])) {
    $otherId = messageOtherParam($_GET["with"], $me);
    $other = messageAdmin($pdo, $otherId);
    $conversation = findConversation($pdo, $me, $otherId);

    // No conversation yet is an empty thread - send_message starts one. A
    // removed admin with no history is unknown.
    if (!$conversation) {
        if ($other["removed"]) {
            v1Error(404, "not_found", "That person isn't on the team.");
        }
        v1Reply(200, ["with" => messagePerson($other), "items" => [], "next_cursor" => null,
            "their_last_read_id" => 0]);
    }

    $stmt = $pdo->prepare("
        SELECT id, sender_id, body, created_at, UNIX_TIMESTAMP(created_at) AS created_ts
        FROM admin_messages
        WHERE conversation_id = ?" . ($after !== null ? " AND id < ?" : "") . "
        ORDER BY id DESC
        LIMIT " . ($limit + 1)
    );
    $stmt->execute($after !== null ? [(int) $conversation["id"], $after] : [(int) $conversation["id"]]);

    $page = v1Page($stmt->fetchAll(), $limit, function ($m) {
        return [(int) $m["id"]];
    }, function ($m) use ($me) {
        return [
            "id" => (int) $m["id"],
            "mine" => (int) $m["sender_id"] === $me,
            "created_at" => v1Time($m["created_ts"]),
            "untrusted_content" => ["body" => (string) $m["body"]],
        ];
    });

    v1Reply(200, array_merge(["with" => messagePerson($other)], $page, [
        // The last of my messages they have seen.
        "their_last_read_id" => theirReadId($conversation, $me),
    ]));
}

/*
| My conversations, the latest message first. last_message_id only grows,
| so it is the keyset.
*/
$stmt = $pdo->prepare("
    SELECT c.*,
           IF(c.admin_low = :me1, c.admin_high, c.admin_low) AS other_id,
           m.body AS last_body, m.sender_id AS last_sender_id, UNIX_TIMESTAMP(m.created_at) AS last_ts,
           (SELECT COUNT(*) FROM admin_messages u
             WHERE u.conversation_id = c.id AND u.sender_id <> :me2
               AND u.id > IF(c.admin_low = :me3, c.low_last_read_id, c.high_last_read_id)) AS unread
    FROM admin_conversations c
    JOIN admin_messages m ON m.id = c.last_message_id
    WHERE (c.admin_low = :me4 OR c.admin_high = :me5)" . ($after !== null ? " AND c.last_message_id < :after" : "") . "
    ORDER BY c.last_message_id DESC
    LIMIT " . ($limit + 1)
);
$params = ["me1" => $me, "me2" => $me, "me3" => $me, "me4" => $me, "me5" => $me];
if ($after !== null) {
    $params["after"] = $after;
}
$stmt->execute($params);

$page = v1Page($stmt->fetchAll(), $limit, function ($c) {
    return [(int) $c["last_message_id"]];
}, function ($c) use ($pdo, $me) {
    $body = (string) $c["last_body"];
    return [
        "with" => messagePerson(messageAdmin($pdo, $c["other_id"])),
        "unread" => (int) $c["unread"],
        "last_message_at" => v1Time($c["last_ts"]),
        "last_is_mine" => (int) $c["last_sender_id"] === $me,
        "untrusted_content" => [
            "last_message" => mb_strlen($body) > 200 ? mb_substr($body, 0, 199) . "…" : $body,
        ],
    ];
});

$page["unread_count"] = messageUnreadCount($pdo, $me);

v1Reply(200, $page);
