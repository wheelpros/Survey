<?php

/*
| Notifications - the caller's own inbox.
|
|   GET  notifications.php?unread=1&cursor=&limit=   list_my_notifications
|   POST notifications.php {"ids": [..]} | {"all": true}  mark_notifications_read
|
| Scope notifications:read. Like notifications.php, the recipient comes from
| the token and never from the request, so there is no way to read anyone
| else's. Titles and bodies quote what people typed (a meeting topic, a form
| title), so they come back under untrusted_content.
|
| Marking read is the one change that skips prepare/confirm: it only ever
| touches the caller's own inbox, and is undone by nothing more than the
| next notification. It is still in the audit log like every MCP call.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireStaff($p);

ensureAnnouncementsTable($pdo);

function unreadCount(PDO $pdo, array $p)
{
    $count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL");
    $count->execute([$p["kind"], $p["id"]]);
    return (int) $count->fetchColumn();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    v1RequireScope($p, "notifications:write");

    $in = json_decode(file_get_contents("php://input"), true);
    $all = is_array($in) && ($in["all"] ?? false) === true;
    $ids = is_array($in) && is_array($in["ids"] ?? null) ? $in["ids"] : [];

    if (!$all && ($ids === [] || count($ids) > 100 || array_filter($ids, function ($id) {
        return !is_int($id) || $id <= 0;
    }))) {
        v1Error(422, "invalid_parameter", "Send ids (1 to 100 notification ids) or all: true.");
    }

    // The recipient predicate is the authorisation: someone else's id simply
    // matches no row.
    $sql = "UPDATE notifications SET read_at = NOW() WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL";
    $params = [$p["kind"], $p["id"]];
    if (!$all) {
        $sql .= " AND id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
        $params = array_merge($params, $ids);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    v1Reply(200, ["marked" => $stmt->rowCount(), "unread_count" => unreadCount($pdo, $p)]);
}

v1RequireMethod("GET");
v1RequireScope($p, "notifications:read");

$limit = v1Limit();
$after = v1IdCursor();

$parts = [["recipient_kind = ? AND recipient_id = ?", [$p["kind"], $p["id"]]]];
if (!empty($_GET["unread"])) {
    $parts[] = ["read_at IS NULL", []];
}
if ($after !== null) {
    $parts[] = ["id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare("
    SELECT id, event_type, title, body, link, read_at, announcement_id, created_at
    FROM notifications
    $where
    ORDER BY id DESC
    LIMIT " . ($limit + 1)
);
$stmt->execute($params);

$page = v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, function ($r) {
    return [
        "id" => (int) $r["id"],
        "event_type" => (string) $r["event_type"],
        "read" => $r["read_at"] !== null,
        // The portal page it opens, relative to the portal's address.
        "link" => v1NullableString($r["link"]),
        "is_announcement" => $r["announcement_id"] !== null,
        "created_at" => (string) $r["created_at"],
        "untrusted_content" => [
            "title" => (string) $r["title"],
            "body" => (string) ($r["body"] ?? ""),
        ],
    ];
});

$page["unread_count"] = unreadCount($pdo, $p);

v1Reply(200, $page);
