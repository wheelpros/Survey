<?php

/*
|--------------------------------------------------------------------------
| The Appointments page (admin-appointments.html, api/calendar.php)
|--------------------------------------------------------------------------
|
| Loaded by run.php after writes.php, whose helpers (post, pageGet, one,
| notes) it uses. Own rows 470-474, so nothing here depends on what the
| other files did to 400-402.
|
|   470 Alice (10)  confirmed, sent by Amy       - cancelled below
|   471 Alice (10)  pending, sent by Amy         - withdrawn below
|   472 Alice (10)  declined                     - nothing to cancel
|   473 Alice (10)  pending, Alice asked         - cancelled, then can't be answered
|   474 Bob   (11)  confirmed                    - not Amy's client
*/

seed($pdo, "appointments", [
    ["id" => 470, "user_id" => 10, "title" => "Appt confirmed", "topic" => "Appt confirmed", "date" => day("+3"), "time" => "10:00:00",
     "status" => "approved", "requested_by" => "admin", "admin_id" => 3],
    ["id" => 471, "user_id" => 10, "title" => "Appt sent", "topic" => "Appt sent", "date" => day("+3"), "time" => "11:00:00",
     "status" => "pending", "requested_by" => "admin", "admin_id" => 3],
    ["id" => 472, "user_id" => 10, "title" => "Appt declined", "topic" => "Appt declined", "date" => day("+3"), "time" => "12:00:00",
     "status" => "rejected", "requested_by" => "user"],
    ["id" => 473, "user_id" => 10, "title" => "Appt asked", "topic" => "Appt asked", "date" => day("+3"), "time" => "13:00:00",
     "status" => "pending", "requested_by" => "user"],
    ["id" => 474, "user_id" => 11, "title" => "Appt Bob", "topic" => "Appt Bob", "date" => day("+3"), "time" => "14:00:00",
     "status" => "approved", "requested_by" => "admin", "admin_id" => 4],
]);

/* The page is meetings only now: no posts, no phases. */
[, $r] = pageGet("api/calendar.php?action=get_admin_calendar", $am);
$ids = array_map("intval", array_column($r["appointments"] ?? [], "id"));
check("appointments: the feed is meetings only",
    ($r["success"] ?? false) === true && !array_key_exists("content", $r) && !array_key_exists("phases", $r)
    && in_array(470, $ids, true) && !in_array(474, $ids, true), json_encode(array_keys($r ?? [])));

[, $r] = pageGet("api/calendar.php?action=get_admin_calendar", $seo);
check("appointments: closed to SEO admins", ($r["success"] ?? true) === false, json_encode($r));

/* Cancelling a confirmed meeting. */
$before = notes($pdo, "user", 10, "appointment_answered");
[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 470, "reason" => "Travelling"]);
check("appointments: a confirmed meeting can be cancelled, and the client is told",
    ($r["success"] ?? false) === true
    && one($pdo, "SELECT status FROM appointments WHERE id = 470")["status"] === "cancelled"
    && notes($pdo, "user", 10, "appointment_answered") === $before + 1, json_encode($r));
check("appointments: the cancellation is in the activity log",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE action = 'meeting.cancelled' AND target_id = 470")["n"] === 1);

[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 470]);
check("appointments: cancelling twice is refused", ($r["message"] ?? "") === "That meeting is already cancelled.", json_encode($r));

[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 472]);
check("appointments: a declined meeting has nothing to cancel", ($r["message"] ?? "") === "A declined meeting has nothing to cancel.", json_encode($r));

[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 474]);
check("appointments: not another admin's client",
    ($r["message"] ?? "") === "That meeting is not yours to cancel."
    && one($pdo, "SELECT status FROM appointments WHERE id = 474")["status"] === "approved", json_encode($r));

[, $r] = post("api/calendar.php?action=cancel_meeting", $seo, ["id" => 471]);
check("appointments: SEO admins cannot cancel",
    ($r["success"] ?? true) === false && one($pdo, "SELECT status FROM appointments WHERE id = 471")["status"] === "pending", json_encode($r));

/* Withdrawing a request the team sent. */
[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 471]);
check("appointments: a request still out can be withdrawn",
    ($r["success"] ?? false) === true && one($pdo, "SELECT status FROM appointments WHERE id = 471")["status"] === "cancelled", json_encode($r));

[, $r] = post("api/calendar.php?action=respond_appointment", "tok-client", ["id" => 471, "status" => "approved"]);
check("appointments: the client can't accept a withdrawn request",
    ($r["success"] ?? true) === false && one($pdo, "SELECT status FROM appointments WHERE id = 471")["status"] === "cancelled", json_encode($r));

/* A client's request, cancelled, stays cancelled. */
[, $r] = post("api/calendar.php?action=cancel_meeting", $am, ["id" => 473]);
check("appointments: a client's request can be called off", ($r["success"] ?? false) === true, json_encode($r));
[, $r] = post("api/calendar.php?action=respond_request", $am, ["id" => 473, "status" => "approved"]);
check("appointments: a cancelled meeting can't be confirmed again",
    ($r["message"] ?? "") === "That meeting was cancelled."
    && one($pdo, "SELECT status FROM appointments WHERE id = 473")["status"] === "cancelled", json_encode($r));

/* The client still sees what happened. */
[, $r] = pageGet("api/calendar.php?action=get_user_calendar", "tok-client");
$statuses = array_column($r["appointments"] ?? [], "status", "id");
check("appointments: the client's calendar keeps the cancelled meeting",
    ($statuses[470] ?? "") === "cancelled", json_encode($statuses));
