<?php

/*
|--------------------------------------------------------------------------
| The booking tools over api/v1 (what the MCP server calls)
|--------------------------------------------------------------------------
|
| Loaded by run.php after booking.php: Amy (3) has saved hours by then (every
| day 09:00-17:00 UTC, 30 minutes, no notice), and Ana (30) is the second
| account manager. Uses writes.php's prepare / confirm / change helpers.
*/

$alice = "tok-client";

/* Reads: guest meetings and the new fields. */
$guestMeeting = (int) one($pdo, "SELECT id FROM appointments WHERE topic = 'Direct call'")["id"];
[$status, $r] = get("calendar.php?id=$guestMeeting", $am);
check("ai booking: a guest meeting reads with its guest, length and zone",
    $status === 200 && array_key_exists("client", $r["data"] ?? []) && $r["data"]["client"] === null && ($r["data"]["guest_email"] ?? "") === "gail@example.test"
    && ($r["data"]["duration_min"] ?? 0) === 30 && ($r["data"]["timezone"] ?? "") === "UTC" && ($r["data"]["source"] ?? "") === "direct"
    && ($r["data"]["untrusted_content"]["guest_name"] ?? "") === "Gail Guest", json_encode($r));
[$status] = get("calendar.php?id=$guestMeeting", $am3);
check("ai booking: not another manager's guest", $status === 404, "HTTP $status");

[, $r] = get("calendar.php?from=" . day("0") . "&to=" . day("+7"), $am);
check("ai booking: get_calendar includes the host's guest meetings",
    in_array($guestMeeting, array_map(function ($m) { return $m["id"]; }, $r["data"]["meetings"] ?? []), true));

/* A guest picks a time, then list_pending_approvals shows it to the host. */
[, $made] = post("api/calendar.php?action=create_booking_request", $am, ["who" => "guest", "guest_name" => "Quinn", "guest_email" => "quinn@example.test", "topic" => "AI pick", "window_days" => 7]);
[, $body] = rawGet("api/public-booking.php?t=" . tokenOf($made["link"] ?? ""));
$qSlot = json_decode($body, true)["booking"]["slots"][0] ?? ["date" => "", "time" => ""];
post("api/public-booking.php", "", ["t" => tokenOf($made["link"] ?? ""), "date" => $qSlot["date"], "time" => $qSlot["time"], "name" => "Quinn", "email" => "quinn@example.test", "message" => "Hi"]);
$qMeeting = (int) one($pdo, "SELECT appointment_id FROM booking_requests WHERE id = ?", [(int) ($made["id"] ?? 0)])["appointment_id"];
[, $r] = get("calendar.php?pending=1", $am);
check("ai booking: a guest's picked time waits on the host", in_array($qMeeting, array_map(function ($m) { return $m["id"]; }, $r["data"]["waiting_on_you"] ?? []), true)
    && $qMeeting > 0, json_encode($r["data"]["waiting_on_you"] ?? $r));

[, $r] = get("calendar.php?booking_requests=1", $am);
$states = array_column($r["data"]["items"] ?? [], "state", "id");
check("ai booking: list_booking_requests shows Amy's links and their state",
    ($states[(int) ($made["id"] ?? 0)] ?? "") === "picked" && !isset($states[600]), json_encode($states));
[, $r] = get("calendar.php?booking_requests=1", $owner);
$ownerStates = array_column($r["data"]["items"] ?? [], "state", "id");
check("ai booking: the owner sees only their own links", isset($ownerStates[600]) && !isset($ownerStates[(int) ($made["id"] ?? 0)]), json_encode($ownerStates));

[$status, $r] = get("calendar.php?open_times=1&days=3", $am);
check("ai booking: get_open_times gives the hours and the free times",
    $status === 200 && ($r["data"]["timezone"] ?? "") === "UTC" && ($r["data"]["availability"]["set"] ?? false) === true
    && count($r["data"]["slots"] ?? []) > 0 && ($r["data"]["availability"]["weekly"]["sun"][0] ?? []) == ["09:00", "17:00"], json_encode($r));
[$status] = get("calendar.php?open_times=1&days=200", $am);
check("ai booking: open times at most 90 days ahead", $status === 422, "HTTP $status");

/* respond_to_meeting answers a guest's pick. */
change("ai booking: confirm a guest's picked time", $am, "respond_to_meeting", ["id" => $qMeeting, "decision" => "accept"]);
check("ai booking: confirmed", one($pdo, "SELECT status FROM appointments WHERE id = ?", [$qMeeting])["status"] === "approved");

/* add_meeting. */
refusedChange("ai booking: add_meeting needs someone", $am, "add_meeting", ["date" => day("+3"), "time" => "10:00", "topic" => "x"], 422);
refusedChange("ai booking: add_meeting refuses a clash without allow_clash", $am, "add_meeting",
    ["client" => 10, "date" => day("+2"), "time" => "11:30", "topic" => "Clash again"], 409);
[$prep] = change("ai booking: add a confirmed guest meeting", $am, "add_meeting",
    ["guest_name" => "Rae", "guest_email" => "rae@example.test", "date" => day("+3"), "time" => "16:00", "topic" => "AI direct"]);
check("ai booking: it is on the calendar, confirmed, for the guest",
    one($pdo, "SELECT status, user_id, guest_email, source, admin_id FROM appointments WHERE topic = 'AI direct'")
    == ["status" => "approved", "user_id" => null, "guest_email" => "rae@example.test", "source" => "direct", "admin_id" => 3]
    && strpos($prep["data"]["summary"] ?? "", "calendar invite") !== false, json_encode($prep));
change("ai booking: allow_clash adds it anyway", $am, "add_meeting",
    ["client" => 10, "date" => day("+2"), "time" => "11:30", "topic" => "Clash on purpose", "allow_clash" => true]);
check("ai booking: the clash went in for the client", one($pdo, "SELECT user_id FROM appointments WHERE topic = 'Clash on purpose'")["user_id"] === 10);
refusedChange("ai booking: not someone else's client", $am, "add_meeting", ["client" => 11, "date" => day("+3"), "time" => "12:00", "topic" => "x"], 404);

/* send_booking_request, withdraw, resend. */
[, $done] = change("ai booking: send a guest a booking link", $am, "send_booking_request",
    ["guest_name" => "Sky", "guest_email" => "sky@example.test", "topic" => "AI link", "window_days" => 7]);
$skyRequest = (int) ($done["data"]["result"]["booking_request_id"] ?? 0);
check("ai booking: the result has the guest's link", $skyRequest > 0 && tokenOf($done["data"]["result"]["link"] ?? "") !== "", json_encode($done));
refusedChange("ai booking: window_days is 7, 14 or 30", $am, "send_booking_request", ["client" => 10, "topic" => "x", "window_days" => 10], 422);
change("ai booking: send a client a booking link", $am, "send_booking_request", ["client" => 10, "topic" => "AI client link"]);
check("ai booking: the client link went to Alice's account",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM booking_request_links l JOIN booking_requests r ON r.id = l.booking_request_id WHERE r.topic = 'AI client link' AND l.user_id = 10")["n"] >= 1);

$oldHash = one($pdo, "SELECT token_hash FROM booking_request_links WHERE booking_request_id = ?", [$skyRequest])["token_hash"];
change("ai booking: resend a link", $am, "resend_booking_request", ["id" => $skyRequest]);
check("ai booking: resending makes a new link", one($pdo, "SELECT token_hash FROM booking_request_links WHERE booking_request_id = ?", [$skyRequest])["token_hash"] !== $oldHash);
refusedChange("ai booking: not another manager's link", $am3, "withdraw_booking_request", ["id" => $skyRequest], 404);
change("ai booking: withdraw a link", $am, "withdraw_booking_request", ["id" => $skyRequest]);
check("ai booking: withdrawn", one($pdo, "SELECT status FROM booking_requests WHERE id = ?", [$skyRequest])["status"] === "cancelled");
refusedChange("ai booking: a withdrawn link can't be resent", $am, "resend_booking_request", ["id" => $skyRequest], 409);

/* cancel_meeting. */
$rae = (int) one($pdo, "SELECT id FROM appointments WHERE topic = 'AI direct'")["id"];
refusedChange("ai booking: not another manager's meeting", $am3, "cancel_meeting", ["id" => $rae], 404);
[$prep] = change("ai booking: cancel a guest meeting", $am, "cancel_meeting", ["id" => $rae, "reason" => "Travelling"]);
check("ai booking: cancelled, and the summary says how they're told",
    one($pdo, "SELECT status FROM appointments WHERE id = ?", [$rae])["status"] === "cancelled"
    && strpos($prep["data"]["summary"] ?? "", "cancellation for their calendar") !== false, json_encode($prep));
refusedChange("ai booking: cancelling twice is refused", $am, "cancel_meeting", ["id" => $rae], 409);
refusedChange("ai booking: SEO admins can't cancel", $seo, "cancel_meeting", ["id" => $guestMeeting], 403);

/* The client's side. */
[, $r] = get("me/overview.php", $alice);
$waitingIds = array_column($r["data"]["booking_requests_waiting_on_you"] ?? [], "id");
check("ai booking: my_overview lists the booking requests waiting on the client", in_array(601, $waitingIds, true), json_encode($waitingIds));
[, $r] = get("me/calendar.php?booking_requests=1", $alice);
$mine = array_values(array_filter($r["data"]["items"] ?? [], function ($x) { return $x["id"] === 601; }));
check("ai booking: list_my_booking_requests gives the times", count($mine) === 1 && count($mine[0]["slots"]) > 0
    && $mine[0]["with"] === "Amy", json_encode($r["data"]["items"] ?? $r));
$pick = $mine[0]["slots"][0] ?? ["date" => day("+1"), "time" => "09:00"];
[$status] = prepare($alice, "pick_meeting_time", ["id" => 601, "date" => $pick["date"], "time" => "03:00"]);
check("ai booking: a time that isn't offered is refused", $status === 409, "HTTP $status");
[$status] = prepare($alice, "pick_meeting_time", ["id" => 600, "date" => $pick["date"], "time" => $pick["time"]]);
check("ai booking: not a request to them", $status === 404, "HTTP $status");
change("ai booking: the client picks a time", $alice, "pick_meeting_time", ["id" => 601, "date" => $pick["date"], "time" => $pick["time"], "message" => "From the assistant"]);
check("ai booking: the pick waits on Amy",
    one($pdo, "SELECT a.status, a.user_id, a.admin_id, a.attendee_message FROM appointments a JOIN booking_requests r ON r.appointment_id = a.id WHERE r.id = 601")
    == ["status" => "pending", "user_id" => 10, "admin_id" => 3, "attendee_message" => "From the assistant"]);
[, $r] = get("me/overview.php", $alice);
check("ai booking: and it leaves the client's list", !in_array(601, array_column($r["data"]["booking_requests_waiting_on_you"] ?? [], "id"), true));
