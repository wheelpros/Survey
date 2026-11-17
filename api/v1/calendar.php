<?php

/*
| Appointments - the meetings the portal's Appointments page shows. Meetings
| only: posts going live are in content.php (list_content), dated phases in
| projects.php (get_project). The file keeps its old name, and the scope is
| still calendar:read, as stored in tokens and role settings.
|
|   GET calendar.php?from=&to=&client=   list_meetings
|   GET calendar.php?pending=1           list_pending_approvals
|   GET calendar.php?id=N                get_meeting
|   GET calendar.php?booking_requests=1  list_booking_requests
|   GET calendar.php?open_times=1&days=  get_open_times (your own)
|
| Scope calendar:read, held by the owner, super admins and account managers -
| calendar.php closes itself to seo_admin. Meetings follow meetingVisibilitySql()
| (meeting-writes.php), like the Appointments page: the clients assigned to
| them and the meetings they host - the owner too, who also gets requests from
| clients nobody is assigned to.
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

$client = v1IntParam("client");
if ($client !== null) {
    // 404s a client this person can't see.
    v1VisibleClient($pdo, $p, $client);
    $parts[] = ["a.user_id = ?", [$client]];
}

[$meetingItems, $truncated] = meetings($pdo, $parts, "a.date ASC, a.time ASC, a.id ASC", CALENDAR_MAX_ROWS);

$data = ["from" => $from, "to" => $to, "meetings" => $meetingItems];

// More than CALENDAR_MAX_ROWS meetings: ask for a shorter range.
$data["truncated"] = $truncated;

v1Reply(200, $data);
