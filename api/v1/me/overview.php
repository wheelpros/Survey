<?php

/*
| GET me/overview.php - my_overview: what is waiting on the client and what
| is coming up, in one call. Each part follows its own client page.
*/

require_once __DIR__ . "/_me.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureAppointmentTables($pdo);
ensureContentColumns($pdo);
ensureProjectTables($pdo);
ensureNotificationsTable($pdo);

$me = meRow($pdo, $p);
$today = date("Y-m-d");

$meetings = $pdo->prepare("
    SELECT a.*, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    WHERE " . ME_MEETINGS_SQL . " AND a.date >= ? AND a.status IN ('pending', 'approved')
    ORDER BY a.date ASC, a.time ASC
    LIMIT 10
");
$meetings->execute([$p["id"], $today]);
$meetings = array_map("meMeetingItem", $meetings->fetchAll());

// Every request W|ZONE sent that the client hasn't answered, however far out.
$waiting = $pdo->prepare("
    SELECT a.*, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    WHERE a.user_id = ? AND a.requested_by = 'admin' AND a.status = 'pending'
    ORDER BY a.date ASC, a.time ASC
");
$waiting->execute([$p["id"]]);

// Released and not yet answered - the dashboard's "pending".
$forms = $pdo->prepare("
    SELECT id, title, created_at FROM surveys
    WHERE assigned_user_id = ? AND status = 'pending'
    ORDER BY id DESC
");
$forms->execute([$p["id"]]);

$company = trim((string) ($me["company_name"] ?? ""));
$content = $pdo->prepare("
    SELECT c.*, " . contentLiveAtSql("c") . " AS live_at
    FROM content c
    WHERE " . meContentSql() . "
    ORDER BY live_at DESC, c.id DESC
    LIMIT 5
");
$content->execute([contentNow(), $company, $company]);

$projects = $pdo->prepare("
    SELECT p.id, p.title, p.status, p.progress, p.start_date, p.end_date
    FROM projects p
    WHERE p.client_id = ? AND p.status <> 'completed'
    ORDER BY p.start_date DESC, p.id DESC
");
$projects->execute([$p["id"]]);

$unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_kind = 'user' AND recipient_id = ? AND read_at IS NULL");
$unread->execute([$p["id"]]);

v1Reply(200, [
    "me" => [
        "name" => (string) $me["name"],
        "email" => (string) $me["email"],
        "company_name" => v1NullableString($me["company_name"]),
    ],
    "meetings_waiting_on_you" => array_map("meMeetingItem", $waiting->fetchAll()),
    "upcoming_meetings" => $meetings,
    "forms_to_fill_in" => array_map(function ($r) {
        return ["id" => (int) $r["id"], "title" => (string) $r["title"], "created_at" => (string) $r["created_at"]];
    }, $forms->fetchAll()),
    "recent_content" => array_map(function ($r) {
        return meContentItem($r, 200);
    }, $content->fetchAll()),
    "active_projects" => array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "status" => (string) $r["status"],
            "status_label" => PROJECT_STATUSES[$r["status"]] ?? (string) $r["status"],
            "progress" => (int) $r["progress"],
            "start_date" => (string) $r["start_date"],
            "end_date" => (string) $r["end_date"],
        ];
    }, $projects->fetchAll()),
    "unread_notifications" => (int) $unread->fetchColumn(),
]);
