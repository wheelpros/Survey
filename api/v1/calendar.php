<?php

/*
| Calendar - meetings with clients, and the content and project phases dated
| in the same days (phases need projects:read as well).
|
|   GET calendar.php?from=&to=&client=   get_calendar
|   GET calendar.php?pending=1           list_pending_approvals
|   GET calendar.php?id=N                get_meeting
|   GET calendar.php?booking_requests=1  list_booking_requests
|   GET calendar.php?open_times=1&days=  get_open_times (your own)
|
| Scope calendar:read, held by the owner, super admins and account managers -
| calendar.php closes itself to seo_admin. Meetings follow meetingVisibilitySql()
| (meeting-writes.php), like the Appointments page: the clients assigned to
| them and the meetings they host - the owner too, who also gets requests from
| clients nobody is assigned to. The content in the range follows content's own
| rule and only appears with content:read as well.
|
| Meeting topics and notes are typed by clients as often as by staff, so they
| come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "calendar:read");

ensureUserProfileColumns($pdo);
ensureAppointmentTables($pdo);
require_once __DIR__ . "/../booking-writes.php";

// The widest range one call may ask for, and the most rows it returns.
const CALENDAR_MAX_DAYS = 366;
const CALENDAR_MAX_ROWS = 500;

// Their own meetings: their clients' and the ones they host - the same list
// as the Appointments page, the Director included.
$scope = meetingVisibilitySql($pdo, $p, "a");

const MEETING_SQL = "
    SELECT a.id, a.user_id, a.title, a.topic, a.notes, a.date, a.time, a.status,
           a.requested_by, a.created_at, a.admin_id, a.duration_min, a.timezone, a.source,
           a.guest_name, a.guest_email, a.attendee_message, a.booking_request_id,
           u.name AS user_name, u.email AS user_email, u.company_name,
           ad.name AS admin_name
    FROM appointments a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN admins ad ON ad.id = a.admin_id
";

function meetingItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        // null for a guest: someone without a portal account (see guest_email).
        "client" => $r["user_id"] ? v1ClientRef($r["user_id"], $r["user_name"], $r["company_name"], $r["user_email"]) : null,
        "guest_email" => v1NullableString($r["guest_email"] ?? null),
        "date" => (string) $r["date"],
        "time" => substr((string) $r["time"], 0, 5),
        // pending | approved | rejected | cancelled
        "status" => (string) $r["status"],
        "duration_min" => (int) ($r["duration_min"] ?: 30),
        // The zone date and time are in; null on meetings made before zones were kept.
        "timezone" => v1NullableString($r["timezone"] ?? null),
        // direct | booking | request | proposal; null on older meetings.
        "source" => v1NullableString($r["source"] ?? null),
        "booking_request_id" => $r["booking_request_id"] ? (int) $r["booking_request_id"] : null,
        // 'admin': W|ZONE asked the client, who answers it.
        // 'user':  the client asked, and an admin answers it.
        "requested_by" => (string) ($r["requested_by"] ?? "admin"),
        // Who asked, or who answered the client's request.
        "admin_name" => v1NullableString($r["admin_name"]),
        "created_at" => (string) $r["created_at"],
        "untrusted_content" => [
            "topic" => (string) ($r["topic"] ?? $r["title"] ?? ""),
            "notes" => (string) ($r["notes"] ?? ""),
            "guest_name" => (string) ($r["guest_name"] ?? ""),
            // What whoever picked the time wrote to the host.
            "message" => (string) ($r["attendee_message"] ?? ""),
        ],
    ];
}

function meetings(PDO $pdo, array $parts, $order, $max)
{
    [$where, $params] = v1Where($parts);
    $stmt = $pdo->prepare(MEETING_SQL . $where . " ORDER BY $order LIMIT " . ($max + 1));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return [array_map("meetingItem", array_slice($rows, 0, $max)), count($rows) > $max];
}

$id = v1IntParam("id");

if ($id !== null) {
    [$found] = meetings($pdo, [["a.id = ?", [$id]], $scope], "a.id", 1);
    if (!$found) {
        v1Error(404, "not_found", "No meeting with that id among your clients' meetings.");
    }
    v1Reply(200, $found[0]);
}

// The booking links still out (and recent ones): yours, or every one for the owner.
if (!empty($_GET["booking_requests"])) {
    ensureBookingTables($pdo);
    $items = array_map(function ($r) {
        return [
            "id" => $r["id"],
            "client" => v1NullableString($r["client"]),
            "guest_email" => v1NullableString($r["guest_email"]),
            "host" => (string) $r["host_name"],
            // open | picked | booked | declined | expired | cancelled
            "state" => $r["state"],
            "duration_min" => $r["duration_min"],
            "timezone" => (string) $r["timezone"],
            "window_from" => (string) $r["window_from"],
            "window_to" => (string) $r["window_to"],
            "meeting_id" => $r["appointment_id"],
            "recipients" => $r["recipients"],
            "opened" => $r["opened"],
            "created_at" => (string) $r["created_at"],
            "untrusted_content" => ["topic" => (string) $r["topic"], "notes" => (string) ($r["notes"] ?? ""), "guest_name" => (string) ($r["guest_name"] ?? "")],
        ];
    }, adminBookingRequests($pdo, $p));
    v1Reply(200, ["items" => $items]);
}

// Your own open times, as a booking link would offer them.
if (!empty($_GET["open_times"])) {
    ensureBookingTables($pdo);
    $days = v1IntParam("days") ?? 14;
    if ($days < 1 || $days > 90) {
        v1Error(422, "invalid_parameter", "days must be between 1 and 90.");
    }
    $a = loadAvailability($pdo, (int) $p["id"]);
    $first = availabilityNow($a)->format("Y-m-d");
    $last = availabilityNow($a)->modify("+$days days")->format("Y-m-d");
    $slots = openSlots($pdo, (int) $p["id"], $first, $last, null, $a);
    v1Reply(200, [
        "timezone" => $a["timezone"],
        "slot_minutes" => (int) $a["slot_minutes"],
        "from" => $first,
        "to" => $last,
        "availability" => [
            "set" => (bool) $a["saved"],
            "weekly" => $a["weekly"],
            "buffer_minutes" => (int) $a["buffer_minutes"],
            "notice_hours" => (int) $a["notice_hours"],
            "horizon_days" => (int) $a["horizon_days"],
            "days_off" => array_map(function ($o) { return ["from" => $o["from"], "to" => $o["to"], "label" => $o["label"]]; }, $a["off"]),
        ],
        "slots" => array_slice($slots, 0, 500),
        "truncated" => count($slots) > 500,
    ]);
}

if (!empty($_GET["pending"])) {
    $order = "a.date ASC, a.time ASC, a.id ASC";
    // A client asked, or someone picked a time from a booking link; one of the
    // admin team answers (calendar.php respond_request).
    [$onYou, $moreOnYou] = meetings($pdo, [
        $scope, ["a.requested_by = 'user' AND a.status = 'pending'", []],
    ], $order, 200);
    // W|ZONE asked; the client hasn't answered yet.
    [$onClient, $moreOnClient] = meetings($pdo, [
        $scope, ["a.requested_by = 'admin' AND a.status = 'pending'", []],
    ], $order, 200);

    v1Reply(200, [
        "waiting_on_you" => $onYou,
        "waiting_on_client" => $onClient,
        "truncated" => $moreOnYou || $moreOnClient,
    ]);
}

$from = v1DateParam("from", date("Y-m-d"));
$to = v1DateParam("to", date("Y-m-d", strtotime($from . " +30 days")));
if ($to < $from) {
    v1Error(422, "invalid_parameter", "to must be on or after from.");
}
if ((strtotime($to) - strtotime($from)) / 86400 > CALENDAR_MAX_DAYS) {
    v1Error(422, "invalid_parameter", "Ask for at most " . CALENDAR_MAX_DAYS . " days at a time.");
}

$parts = [$scope, ["a.date BETWEEN ? AND ?", [$from, $to]]];
$company = null;

$client = v1IntParam("client");
if ($client !== null) {
    $company = trim((string) (v1VisibleClient($pdo, $p, $client)["company_name"] ?? ""));
    $parts[] = ["a.user_id = ?", [$client]];
}

[$meetingItems, $truncated] = meetings($pdo, $parts, "a.date ASC, a.time ASC, a.id ASC", CALENDAR_MAX_ROWS);

$data = ["from" => $from, "to" => $to, "meetings" => $meetingItems];

if (hasScope($p, "content:read")) {
    ensureContentColumns($pdo);
    $now = contentNow();
    $liveAt = contentLiveAtSql("c");

    $contentParts = [
        v1SeesAllContent($p) ? ["", []] : contentVisibleSql("c", (int) $p["id"]),
        ["$liveAt BETWEEN ? AND ?", [$from . " 00:00:00", $to . " 23:59:59"]],
    ];
    if ($client !== null) {
        // Tied by company name; a client without one has no content.
        $contentParts[] = $company === "" ? ["1 = 0", []] : ["c.client = ?", [$company]];
    }
    [$where, $params] = v1Where($contentParts);

    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.client, c.type_label,
               " . contentStatusSql("c") . " AS status, $liveAt AS live_at
        FROM content c
        $where
        ORDER BY live_at ASC, c.id ASC
        LIMIT " . (CALENDAR_MAX_ROWS + 1)
    );
    $stmt->execute(array_merge([$now], $params));
    $rows = $stmt->fetchAll();

    $truncated = $truncated || count($rows) > CALENDAR_MAX_ROWS;
    $data["content"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "client" => v1NullableString($r["client"]),
            "type_label" => v1NullableString($r["type_label"]),
            "status" => (string) $r["status"],
            "live_at" => v1DateTime($r["live_at"]),
        ];
    }, array_slice($rows, 0, CALENDAR_MAX_ROWS));
}

/*
| The project phases dated in the range - what admin-calendar.html shows
| beside meetings and posts. Only with projects:read as well, and within
| projectScopeSql(), the Project Management rule. Someone else's draft stays
| out unless the person may see every draft, as on project-details.html.
*/
if (hasScope($p, "projects:read")) {
    ensureProjectTables($pdo);

    $phaseParts = [
        v1ProjectScopeSql($p),
        ["t.scheduled_date BETWEEN ? AND ?", [$from, $to]],
    ];
    if (!can($p, "tasks.see_drafts", $pdo)) {
        $phaseParts[] = [
            "(t.status <> 'draft' OR t.created_by_admin_id = ? OR p.account_manager_admin_id = ?)",
            [(int) $p["id"], (int) $p["id"]],
        ];
    }
    if ($client !== null) {
        $phaseParts[] = ["p.client_id = ?", [$client]];
    }
    [$where, $params] = v1Where($phaseParts);

    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.status, t.scheduled_date, t.scheduled_time, t.is_complete,
               " . TASK_IS_LIVE_SQL . " AS is_live,
               p.id AS project_id, p.title AS project_title, p.client_id,
               u.name AS user_name, u.company_name
        FROM project_tasks t
        JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = p.client_id
        $where
        ORDER BY t.scheduled_date ASC, t.scheduled_time ASC, t.id ASC
        LIMIT " . (CALENDAR_MAX_ROWS + 1)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $truncated = $truncated || count($rows) > CALENDAR_MAX_ROWS;
    $data["phases"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "project" => ["id" => (int) $r["project_id"], "title" => (string) $r["project_title"]],
            "client" => v1ClientRef($r["client_id"], $r["user_name"], $r["company_name"]),
            "date" => (string) $r["scheduled_date"],
            "time" => $r["scheduled_time"] === null ? null : substr((string) $r["scheduled_time"], 0, 5),
            // draft | published
            "status" => (string) $r["status"],
            // Whether the client can see it yet.
            "is_live" => (int) $r["is_live"] === 1,
            "is_complete" => (int) $r["is_complete"] === 1,
        ];
    }, array_slice($rows, 0, CALENDAR_MAX_ROWS));
}

// More than CALENDAR_MAX_ROWS of any of them: ask for a shorter range.
$data["truncated"] = $truncated;

v1Reply(200, $data);
