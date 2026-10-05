<?php

/*
| GET me/calendar.php?from=&to=   my_calendar
| GET me/calendar.php?id=N        one of my meetings
|
| The client's meetings (calendar.php get_user_calendar) and the content
| that went live in the same days (user-content.php) - what the Dashboard
| calendar shows. Defaults to the next 30 days; at most 366 at a time.
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
    $stmt->execute([$id, $p["id"]]);
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
$stmt->execute([$p["id"], $from, $to]);
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

v1Reply(200, [
    "from" => $from,
    "to" => $to,
    "meetings" => array_map("meMeetingItem", array_slice($meetings, 0, 500)),
    "content" => array_map(function ($r) {
        return meContentItem($r, 200);
    }, array_slice($content, 0, 500)),
    "truncated" => count($meetings) > 500 || count($content) > 500,
]);
