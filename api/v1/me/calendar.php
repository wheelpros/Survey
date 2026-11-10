<?php

/*
| GET me/calendar.php?from=&to=   my_calendar
| GET me/calendar.php?id=N        one of my meetings
|
| The client's meetings (calendar.php get_user_calendar), the content that
| went live in the same days (user-content.php) and their projects' live
| phases (user-projects.php ?phases=1) - what the Dashboard calendar shows. Defaults to the next 30 days; at most 366 at a time.
*/

require_once __DIR__ . "/_me.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureAppointmentTables($pdo);
ensureContentColumns($pdo);

const ME_MEETING_SELECT = "
    SELECT a.*, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
";

$id = v1IntParam("id");
if ($id !== null) {
    $stmt = $pdo->prepare(ME_MEETING_SELECT . " WHERE a.id = ? AND " . ME_MEETINGS_SQL . " LIMIT 1");
    $stmt->execute([$id, $p["account_id"]]);
    $row = $stmt->fetch();
    if (!$row) {
        v1Error(404, "not_found", "No meeting of yours with that id.");
    }
    v1Reply(200, meMeetingItem($row));
}

$from = v1DateParam("from", date("Y-m-d"));
$to = v1DateParam("to", date("Y-m-d", strtotime($from . " +30 days")));
if ($to < $from) {
    v1Error(422, "invalid_parameter", "to must be on or after from.");
}
if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
    v1Error(422, "invalid_parameter", "Ask for at most 366 days at a time.");
}

$stmt = $pdo->prepare(ME_MEETING_SELECT . "
    WHERE " . ME_MEETINGS_SQL . " AND a.date BETWEEN ? AND ?
    ORDER BY a.date ASC, a.time ASC, a.id ASC
    LIMIT 501
");
$stmt->execute([$p["account_id"], $from, $to]);
$meetings = $stmt->fetchAll();

$company = trim((string) (meRow($pdo, $p)["company_name"] ?? ""));
$liveAt = contentLiveAtSql("c");
$stmt = $pdo->prepare("
    SELECT c.*, $liveAt AS live_at
    FROM content c
    WHERE " . meContentSql() . " AND $liveAt BETWEEN ? AND ?
    ORDER BY live_at ASC, c.id ASC
    LIMIT 501
");
$stmt->execute([contentNow(), $company, $company, $from . " 00:00:00", $to . " 23:59:59"]);
$content = $stmt->fetchAll();

// The live phases of the account's projects in the range - what the Dashboard
// calendar shows beside meetings and posts. A draft or a phase dated after
// today does not exist for the client (TASK_IS_LIVE_SQL), as in user-projects.php.
ensureProjectTables($pdo);
$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.scheduled_date, t.scheduled_time, t.is_complete,
           p.id AS project_id, p.title AS project_title
    FROM project_tasks t
    JOIN projects p ON p.id = t.project_id AND p.client_id = ?
    WHERE " . TASK_IS_LIVE_SQL . " AND t.scheduled_date BETWEEN ? AND ?
    ORDER BY t.scheduled_date ASC, t.scheduled_time ASC, t.id ASC
    LIMIT 501
");
$stmt->execute([$p["account_id"], $from, $to]);
$phases = $stmt->fetchAll();

v1Reply(200, [
    "from" => $from,
    "to" => $to,
    "meetings" => array_map("meMeetingItem", array_slice($meetings, 0, 500)),
    "content" => array_map(function ($r) {
        return meContentItem($r, 200);
    }, array_slice($content, 0, 500)),
    "phases" => array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "project" => ["id" => (int) $r["project_id"], "title" => (string) $r["project_title"]],
            "date" => (string) $r["scheduled_date"],
            "time" => $r["scheduled_time"] === null ? null : substr((string) $r["scheduled_time"], 0, 5),
            "is_complete" => (int) $r["is_complete"] === 1,
        ];
    }, array_slice($phases, 0, 500)),
    "truncated" => count($meetings) > 500 || count($content) > 500 || count($phases) > 500,
]);
