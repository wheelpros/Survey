<?php

/*
| Notifications - the caller's own inbox.
|
|   GET notifications.php?unread=1&cursor=&limit=   list_my_notifications
|
| Scope notifications:read. Like notifications.php, the recipient comes from
| the token and never from the request, so there is no way to read anyone
| else's. Titles and bodies quote what people typed (a meeting topic, a form
| title), so they come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "notifications:read");

ensureAnnouncementsTable($pdo);

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

$count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL");
$count->execute([$p["kind"], $p["id"]]);
$page["unread_count"] = (int) $count->fetchColumn();

v1Reply(200, $page);
