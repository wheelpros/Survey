<?php

/*
|--------------------------------------------------------------------------
| Availability, adding directly, booking requests (api/calendar.php,
| api/public-booking.php, api/booking-writes.php)
|--------------------------------------------------------------------------
|
| Loaded by run.php after appointments.php. Amy (3) books; Andy (4) is
| another account manager; Olivia (1) the owner. Amy's hours: every day
| 09:00-17:00 UTC, 30 minutes, no gap, no notice, one day off.
*/

/** The raw body and status of a browser endpoint. */
function rawGet($path, $token = "")
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true, "timeout" => 10],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), (string) $body];
}

/** A POST whose reply may not be JSON: [status, raw body]. */
function rawPost($path, array $body)
{
    global $port;
    $reply = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["method" => "POST", "header" => "Content-Type: application/json", "ignore_errors" => true, "timeout" => 20, "content" => json_encode($body)],
    ]));
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), (string) $reply];
}

/** The token at the end of a booking link. */
function tokenOf($link)
{
    return preg_match('/[?&]t=([a-f0-9]{64})$/', (string) $link, $m) ? $m[1] : "";
}

// Ana: another account manager, assigned Bob only. Seeded here because Andy's
// session is gone by this point in the run.
seed($pdo, "admins", [["id" => 30, "name" => "Ana", "email" => "ana@w.test", "role" => "account_manager", "session_token" => "tok-am3"]]);
seed($pdo, "admin_user_assignments", [["admin_id" => 30, "user_id" => 11]]);
$am3 = "tok-am3";

$every = array_fill_keys(["mon", "tue", "wed", "thu", "fri", "sat", "sun"], [["09:00", "17:00"]]);
$amyHours = ["timezone" => "UTC", "slot_minutes" => 30, "buffer_minutes" => 0, "notice_hours" => 0, "horizon_days" => 30,
    "weekly" => $every, "off" => [["from" => day("+5"), "to" => day("+5"), "label" => "Training"]]];

[, $r] = post("api/calendar.php?action=save_availability", $am, array_merge($amyHours, ["weekly" => array_merge($every, ["mon" => [["09:00", "12:00"], ["11:00", "15:00"]]])]));
check("booking: overlapping hours are refused", ($r["message"] ?? "") === "Monday's hours overlap. Merge them or move one.", json_encode($r));
[, $r] = post("api/calendar.php?action=save_availability", $am, array_merge($amyHours, ["timezone" => "Mars/Base"]));
check("booking: an unknown time zone is refused", ($r["message"] ?? "") === "Pick a time zone from the list.", json_encode($r));
[, $r] = post("api/calendar.php?action=save_availability", $am, $amyHours);
check("booking: availability saves", ($r["success"] ?? false) === true && ($r["availability"]["off"][0]["label"] ?? "") === "Training", json_encode($r));

[, $r] = pageGet("api/calendar.php?action=open_slots&days=7", $am);
$slotDays = array_unique(array_column($r["slots"] ?? [], "date"));
check("booking: open times skip the day off", ($r["count"] ?? 0) > 0 && !in_array(day("+5"), $slotDays, true), json_encode($r["count"] ?? $r));

/* Adding directly: a guest, then a clash. */
[, $r] = post("api/calendar.php?action=add_meeting", $am, ["who" => "guest", "guest_name" => "Gail Guest", "guest_email" => "gail@example.test",
    "topic" => "Direct call", "date" => day("+2"), "time" => "11:30"]);
$direct = one($pdo, "SELECT * FROM appointments WHERE topic = 'Direct call'");
check("booking: a guest meeting is added, confirmed",
    ($r["success"] ?? false) === true && $direct && $direct["status"] === "approved" && $direct["user_id"] === null
    && $direct["guest_email"] === "gail@example.test" && $direct["source"] === "direct" && (int) $direct["duration_min"] === 30, json_encode($r));

[, $r] = post("api/calendar.php?action=add_meeting", $am, ["who" => "client", "client" => "Acme Ltd", "topic" => "Clash", "date" => day("+2"), "time" => "11:30"]);
check("booking: a clash is asked about first", ($r["needs_force"] ?? false) === true
    && strpos($r["message"] ?? "", "clashes with \"Direct call\"") !== false
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM appointments WHERE topic = 'Clash'")["n"] === 0, json_encode($r));
[, $r] = post("api/calendar.php?action=add_meeting", $am, ["who" => "client", "client" => "Acme Ltd", "topic" => "Clash", "date" => day("+2"), "time" => "11:30", "force" => true]);
check("booking: and added when confirmed anyway", ($r["success"] ?? false) === true
    && one($pdo, "SELECT user_id, status FROM appointments WHERE topic = 'Clash'") == ["user_id" => 10, "status" => "approved"], json_encode($r));

[, $r] = pageGet("api/calendar.php?action=open_slots&days=7", $am);
$taken = array_filter($r["slots"] ?? [], function ($s) { return $s["date"] === day("+2") && $s["time"] === "11:30"; });
check("booking: a booked time is no longer open", !$taken);

[, $r] = pageGet("api/calendar.php?action=get_admin_calendar", $am);
check("booking: the host sees the guest meeting", in_array((int) $direct["id"], array_map("intval", array_column($r["appointments"] ?? [], "id")), true));
[, $r] = pageGet("api/calendar.php?action=get_admin_calendar", $am3);
check("booking: another manager doesn't", !in_array((int) $direct["id"], array_map("intval", array_column($r["appointments"] ?? [], "id")), true));
[, $r] = pageGet("api/calendar.php?action=get_admin_calendar", $owner);
check("booking: nor does the owner - it is Amy's", !in_array((int) $direct["id"], array_map("intval", array_column($r["appointments"] ?? [], "id")), true));
check("booking: but the owner can still book with every client", count($r["clients"] ?? []) >= 3, json_encode($r["clients"] ?? $r));

/* The invite. */
[$status, $ics] = rawGet("api/calendar.php?action=download_ics&id=" . (int) $direct["id"], $am);
check("booking: the .ics names the meeting once, in UTC",
    $status === 200 && strpos($ics, "BEGIN:VCALENDAR") === 0 && strpos($ics, "UID:appointment-" . $direct["id"] . "@portal.test") !== false
    && strpos($ics, "DTSTART:" . str_replace("-", "", day("+2")) . "T113000Z") !== false
    && strpos($ics, "ATTENDEE;CN=\"Gail Guest\"") !== false && strpos($ics, "SUMMARY:Direct call") !== false, substr($ics, 0, 400));
[, $r] = pageGet("api/calendar.php?action=download_ics&id=" . (int) $direct["id"], $am3);
check("booking: not another manager's .ics", ($r["success"] ?? true) === false);

require_once $root . "/api/ics.php";
$cairo = meetingIcs(["id" => 1, "date" => "2026-12-01", "time" => "10:00:00", "duration_min" => 45, "timezone" => "Africa/Cairo", "topic" => "A, b; c"], ["Host", "h@x.test"], [], "CANCEL");
check("booking: a zone's time converts to UTC, cancelling escapes the text",
    strpos($cairo, "DTSTART:20261201T080000Z") !== false && strpos($cairo, "DTEND:20261201T084500Z") !== false
    && strpos($cairo, "METHOD:CANCEL") !== false && strpos($cairo, "SUMMARY:A\\, b\\; c") !== false, $cairo);

/* A guest booking request, end to end. */
[, $r] = post("api/calendar.php?action=create_booking_request", $am, ["who" => "guest", "guest_name" => "Pat Picker", "guest_email" => "pat@example.test",
    "topic" => "Discovery", "window_days" => 7]);
$patToken = tokenOf($r["link"] ?? "");
$patRequest = (int) ($r["id"] ?? 0);
check("booking: a guest request gives a link", ($r["success"] ?? false) === true && $patToken !== "" && ($r["slots"] ?? 0) > 0, json_encode($r));
check("booking: only the token's hash is kept",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM booking_request_links WHERE token_hash = ?", [hash("sha256", $patToken)])["n"] === 1
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM booking_request_links WHERE token_hash = ?", [$patToken])["n"] === 0);

[$status, $body] = rawGet("api/public-booking.php?t=" . $patToken);
$b = json_decode($body, true)["booking"] ?? [];
check("booking: the link shows the open times, never the host's email",
    $status === 200 && ($b["state"] ?? "") === "open" && count($b["slots"] ?? []) > 0 && ($b["host"] ?? "") === "Amy"
    && strpos($body, "a@w.test") === false && ($b["you"]["email"] ?? "") === "pat@example.test", $body);
check("booking: opening the link is noted", one($pdo, "SELECT opened_at FROM booking_request_links WHERE booking_request_id = ?", [$patRequest])["opened_at"] !== null);

$slot = $b["slots"][0] ?? ["date" => day("+1"), "time" => "09:00"];
[$refusedStatus, $refusedBody] = rawPost("api/public-booking.php", ["t" => $patToken, "date" => $slot["date"], "time" => "08:00", "name" => "Pat", "email" => "pat@example.test"]);
check("booking: a refused pick still answers in JSON", is_array(json_decode($refusedBody, true)), "$refusedStatus " . substr($refusedBody, 0, 800));
[$status, $r] = post("api/public-booking.php", "", ["t" => $patToken, "date" => $slot["date"], "time" => "08:00", "name" => "Pat", "email" => "pat@example.test"]);
check("booking: a time outside the open ones is refused", $status === 409 && ($r["message"] ?? "") === "That time was just taken. Pick another one.", json_encode($r));
[$status, $r] = post("api/public-booking.php", "", ["t" => $patToken, "date" => $slot["date"], "time" => $slot["time"], "name" => "", "email" => "pat@example.test"]);
check("booking: a guest must give a name", $status === 422, json_encode($r));

$hostNotes = notes($pdo, "admin", 3, "appointment_request");
[$status, $r] = post("api/public-booking.php", "", ["t" => $patToken, "date" => $slot["date"], "time" => $slot["time"], "name" => "Pat Picker", "email" => "pat@example.test", "message" => "Looking forward"]);
$picked = one($pdo, "SELECT * FROM appointments WHERE booking_request_id = ?", [$patRequest]);
check("booking: picking a time makes a meeting waiting on the host",
    $status === 200 && ($r["booking"]["state"] ?? "") === "picked" && $picked && $picked["status"] === "pending"
    && $picked["requested_by"] === "user" && (int) $picked["admin_id"] === 3 && $picked["attendee_message"] === "Looking forward"
    && notes($pdo, "admin", 3, "appointment_request") === $hostNotes + 1, json_encode($r));

[$status, $r] = post("api/public-booking.php", "", ["t" => $patToken, "date" => $slot["date"], "time" => $slot["time"], "name" => "Pat", "email" => "pat@example.test"]);
check("booking: the same link can't pick twice", $status === 409 && ($r["message"] ?? "") === "A time was already picked for this meeting.", json_encode($r));

[, $r] = pageGet("api/calendar.php?action=open_slots&days=7", $am);
$still = array_filter($r["slots"] ?? [], function ($s) use ($slot) { return $s == $slot; });
check("booking: a picked time is held while it waits", !$still);

[, $r] = post("api/calendar.php?action=respond_request", $am3, ["id" => (int) $picked["id"], "status" => "approved"]);
check("booking: another manager can't confirm Amy's guest", ($r["message"] ?? "") === "That request is not yours to answer.", json_encode($r));
[, $r] = post("api/calendar.php?action=respond_request", $am, ["id" => (int) $picked["id"], "status" => "approved"]);
[, $body] = rawGet("api/public-booking.php?t=" . $patToken);
check("booking: confirming books it, and the link says so",
    ($r["success"] ?? false) === true && one($pdo, "SELECT status FROM appointments WHERE id = ?", [(int) $picked["id"]])["status"] === "approved"
    && one($pdo, "SELECT status FROM booking_requests WHERE id = ?", [$patRequest])["status"] === "closed"
    && (json_decode($body, true)["booking"]["state"] ?? "") === "booked", $body);

/* Two requests, one time: the first pick wins. */
[, $r1] = post("api/calendar.php?action=create_booking_request", $am, ["who" => "guest", "guest_name" => "One", "guest_email" => "one@example.test", "topic" => "Race one", "window_days" => 7]);
[, $r2] = post("api/calendar.php?action=create_booking_request", $am, ["who" => "guest", "guest_name" => "Two", "guest_email" => "two@example.test", "topic" => "Race two", "window_days" => 7]);
[, $body] = rawGet("api/public-booking.php?t=" . tokenOf($r1["link"] ?? ""));
$raceSlot = json_decode($body, true)["booking"]["slots"][0] ?? ["date" => "", "time" => ""];
[$s1] = post("api/public-booking.php", "", ["t" => tokenOf($r1["link"] ?? ""), "date" => $raceSlot["date"], "time" => $raceSlot["time"], "name" => "One", "email" => "one@example.test"]);
[$s2, $r] = post("api/public-booking.php", "", ["t" => tokenOf($r2["link"] ?? ""), "date" => $raceSlot["date"], "time" => $raceSlot["time"], "name" => "Two", "email" => "two@example.test"]);
check("booking: a time taken by one request can't be taken by another", $s1 === 200 && $s2 === 409
    && ($r["message"] ?? "") === "That time was just taken. Pick another one.", "$s1 $s2 " . json_encode($r));

/* Resending and withdrawing. */
$oldToken = tokenOf($r2["link"] ?? "");
[, $r] = post("api/calendar.php?action=renew_booking_links", $am, ["id" => (int) ($r2["id"] ?? 0), "email" => false]);
$newToken = tokenOf($r["link"] ?? "");
[$oldStatus] = rawGet("api/public-booking.php?t=" . $oldToken);
[$newStatus] = rawGet("api/public-booking.php?t=" . $newToken);
check("booking: a new link replaces the old one", $newToken !== "" && $newToken !== $oldToken && $oldStatus === 404 && $newStatus === 200, json_encode($r));

[, $r] = post("api/calendar.php?action=cancel_booking_request", $am3, ["id" => (int) ($r2["id"] ?? 0)]);
check("booking: another manager can't withdraw it", ($r["message"] ?? "") === "That booking request is not yours.", json_encode($r));
[, $r] = post("api/calendar.php?action=cancel_booking_request", $am, ["id" => (int) ($r2["id"] ?? 0)]);
[, $body] = rawGet("api/public-booking.php?t=" . $newToken);
check("booking: a withdrawn request's link says so", ($r["success"] ?? false) === true && (json_decode($body, true)["booking"]["state"] ?? "") === "cancelled", $body);
[, $r] = post("api/calendar.php?action=cancel_booking_request", $am, ["id" => (int) ($r1["id"] ?? 0)]);
check("booking: a picked request is answered, not withdrawn", ($r["message"] ?? "") === "A time was already picked. Confirm or reject that meeting instead.", json_encode($r));

[$status, $r] = post("api/public-booking.php", "", ["t" => str_repeat("a", 64), "date" => day("+1"), "time" => "10:00"]);
check("booking: an unknown link is refused", $status === 404 && ($r["state"] ?? "") === "invalid", json_encode($r));

/* A client booking request, picked in the portal. */
[, $r] = post("api/calendar.php?action=create_booking_request", $am, ["who" => "client", "client" => "Acme Ltd", "topic" => "Portal pick", "window_days" => 14]);
$clientRequest = (int) ($r["id"] ?? 0);
check("booking: a client request goes to the company's people, no link back", ($r["success"] ?? false) === true && array_key_exists("link", $r) && $r["link"] === null
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM booking_request_links WHERE booking_request_id = ? AND user_id = 10", [$clientRequest])["n"] >= 1, json_encode($r));
[, $r] = pageGet("api/calendar.php?action=get_booking_requests", "tok-client");
$mine = array_values(array_filter($r["booking_requests"] ?? [], function ($x) use ($clientRequest) { return $x["id"] === $clientRequest; }));
check("booking: the client sees it with open times", count($mine) === 1 && count($mine[0]["slots"]) > 0, json_encode($r));
$clientSlot = $mine[0]["slots"][0] ?? ["date" => "", "time" => ""];
[, $r] = pageGet("api/calendar.php?action=get_booking_requests", "tok-client2");
check("booking: another client doesn't", !array_filter($r["booking_requests"] ?? [], function ($x) use ($clientRequest) { return $x["id"] === $clientRequest; }), json_encode($r));
[, $r] = post("api/calendar.php?action=pick_booking_slot", "tok-client2", ["id" => $clientRequest, "date" => $clientSlot["date"], "time" => $clientSlot["time"]]);
check("booking: nor can they pick from it", ($r["message"] ?? "") === "That booking request isn't yours.", json_encode($r));
[, $r] = post("api/calendar.php?action=pick_booking_slot", "tok-client", ["id" => $clientRequest, "date" => $clientSlot["date"], "time" => $clientSlot["time"], "message" => "Works for us"]);
check("booking: the client picks a time in the portal", ($r["success"] ?? false) === true
    && one($pdo, "SELECT user_id, status, source FROM appointments WHERE booking_request_id = ?", [$clientRequest]) == ["user_id" => 10, "status" => "pending", "source" => "booking"], json_encode($r));

[, $r] = post("api/calendar.php?action=create_booking_request", $seo, ["who" => "guest", "guest_name" => "X", "guest_email" => "x@example.test", "topic" => "No", "window_days" => 7]);
check("booking: SEO admins can't send booking requests", ($r["success"] ?? true) === false, json_encode($r));
