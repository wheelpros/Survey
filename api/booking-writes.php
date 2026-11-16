<?php

/*
|--------------------------------------------------------------------------
| Booking: adding a meeting directly, and booking requests
|--------------------------------------------------------------------------
|
| The two ways staff book time on the Appointments page:
|
|   addMeetingDirectly()    confirmed at once; the attendee gets the invite
|   createBookingRequest()  the attendee picks one of the host's open times
|                           (availability.php), then the host confirms or
|                           rejects it - answerClientMeetingRequest()
|
| An attendee is a client company (every account at it that the admin can
| reach) or a guest: a name and an email, no portal login.
|
| A booking request sends each person their own link, book.html?t=<token>.
| Only the token's sha256 is stored. The first person to pick a time takes it
| for everyone; the others' links then say it is booked. Picking happens under
| a lock on the host, so two people can never take the same time.
|
*/

require_once __DIR__ . "/meeting-writes.php";
require_once __DIR__ . "/availability.php";

const BOOKING_WINDOWS = [7, 14, 30];

/**
 * "client" (a company) or "guest" (name and email), checked. api/v1 names a
 * client by account instead: "account" is that users row, already checked as
 * one the admin may see; it fans out to its company like the page does, or
 * stays that one account when it has no company name.
 */
function bookingTarget(PDO $pdo, array $admin, array $input)
{
    if (!empty($input["account"])) {
        $account = $input["account"];
        $company = trim((string) ($account["company_name"] ?? ""));
        $accounts = $company !== "" ? meetingTargetsForCompany($pdo, $admin, $company) : [$account];
        return ["kind" => "client", "company" => $company !== "" ? $company : (string) $account["name"], "accounts" => $accounts ?: [$account]];
    }

    if (($input["who"] ?? "client") === "guest") {
        $name = mb_substr(trim((string) ($input["guest_name"] ?? "")), 0, 150);
        $email = mb_substr(trim((string) ($input["guest_email"] ?? "")), 0, 190);
        if ($name === "") {
            throw new PortalWriteError("Add the guest's name.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalWriteError("Add a valid email for the guest.");
        }
        return ["kind" => "guest", "name" => $name, "email" => $email];
    }

    $company = trim((string) ($input["client"] ?? ""));
    if ($company === "") {
        throw new PortalWriteError("Pick the client.");
    }
    $accounts = meetingTargetsForCompany($pdo, $admin, $company);
    if (!$accounts) {
        throw new PortalWriteError("No client accounts carry that company name.", 404);
    }
    return ["kind" => "client", "company" => $company, "accounts" => $accounts];
}

function bookingTopic($topic)
{
    $topic = mb_substr(trim((string) $topic), 0, 200);
    if ($topic === "") {
        throw new PortalWriteError("Say what the meeting is about.");
    }
    return $topic;
}

/** The host's open times in a booking request's window, as of now. */
function bookingRequestSlots(PDO $pdo, array $request)
{
    $a = loadAvailability($pdo, (int) $request["admin_id"]);
    return openSlots($pdo, (int) $request["admin_id"], $request["window_from"], $request["window_to"], (int) $request["duration_min"], $a);
}

/*
|--------------------------------------------------------------------------
| Adding a meeting directly
|--------------------------------------------------------------------------
*/

/**
 * A meeting confirmed straight away, at the time the admin chose, with the
 * admin as host. A time that clashes or is outside their hours is refused
 * with 409 and the reasons, unless $force - the page asks first.
 *
 * Returns the new appointment ids.
 */
function addMeetingDirectly(PDO $pdo, array $admin, array $input, $force = false)
{
    $target = bookingTarget($pdo, $admin, $input);
    $topic = bookingTopic($input["topic"] ?? "");
    $notes = trim((string) ($input["notes"] ?? ""));
    $date = (string) ($input["date"] ?? "");
    $time = substr((string) ($input["time"] ?? ""), 0, 5);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || hhmmToMinutes($time) === null) {
        throw new PortalWriteError("Pick the date and time.");
    }

    $a = loadAvailability($pdo, (int) $admin["id"]);
    $minutes = (int) $a["slot_minutes"];
    if ($date . " " . $time < availabilityNow($a)->format("Y-m-d H:i")) {
        throw new PortalWriteError("Pick a time that hasn't passed.");
    }

    if (!$force) {
        $warnings = meetingTimeWarnings($pdo, (int) $admin["id"], $date, $time, $minutes);
        if ($warnings) {
            throw new PortalWriteError(implode(" ", $warnings), 409);
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, notes, requested_by, admin_id, client,
             duration_min, timezone, guest_name, guest_email, source)
        VALUES (?, ?, ?, ?, 'approved', ?, ?, 'admin', ?, ?, ?, ?, ?, ?, 'direct')
    ");

    $rows = [];
    $accounts = $target["kind"] === "client" ? $target["accounts"] : [null];
    foreach ($accounts as $account) {
        $insert->execute([
            $account ? (int) $account["id"] : null,
            $topic, $date, $time . ":00", $topic,
            $notes !== "" ? $notes : null,
            (int) $admin["id"],
            $account ? $target["company"] : null,
            $minutes, $a["timezone"],
            $account ? null : $target["name"],
            $account ? null : $target["email"],
        ]);
        $id = (int) $pdo->lastInsertId();
        $rows[] = [
            "id" => $id, "user_id" => $account ? (int) $account["id"] : null, "title" => $topic, "topic" => $topic,
            "notes" => $notes, "date" => $date, "time" => $time, "admin_id" => (int) $admin["id"],
            "duration_min" => $minutes, "timezone" => $a["timezone"],
            "guest_name" => $account ? null : $target["name"], "guest_email" => $account ? null : $target["email"],
        ];
    }

    $label = $target["kind"] === "client" ? $target["company"] : $target["name"] . " (guest)";
    logEvent($pdo, EVENT_MEETING_BOOKED, "meeting", $rows[0]["id"], $topic,
        "Booked a meeting with " . $label . ": \"" . $topic . "\" on " . $date . " at " . $time,
        $target["kind"] === "client" && count($accounts) === 1 ? $accounts[0]["id"] : null);

    foreach ($rows as $row) {
        tellMeetingAttendees($pdo, $admin, $row, "booked");
    }

    return array_column($rows, "id");
}

/*
|--------------------------------------------------------------------------
| Booking requests
|--------------------------------------------------------------------------
*/

/** A fresh link token: [raw token for the URL, sha256 to store]. */
function bookingToken()
{
    $raw = bin2hex(random_bytes(32));
    return [$raw, hash("sha256", $raw)];
}

function bookingLinkUrl($token)
{
    return APP_URL . "/book.html?t=" . $token;
}

/**
 * Sends a booking request: one row, and a link per person - every person on
 * each of the company's accounts, or the guest. $windowDays is how many days
 * from today they may pick in (capped by the host's "bookable up to").
 *
 * Returns ["id" => request id, "link" => the guest's link (guests only),
 *          "slots" => how many open times the window has now].
 */
function createBookingRequest(PDO $pdo, array $admin, array $input)
{
    $target = bookingTarget($pdo, $admin, $input);
    $topic = bookingTopic($input["topic"] ?? "");
    $notes = trim((string) ($input["notes"] ?? ""));
    $windowDays = (int) ($input["window_days"] ?? 14);
    if (!in_array($windowDays, BOOKING_WINDOWS, true)) {
        throw new PortalWriteError("Pick how long they have to choose.");
    }

    $a = loadAvailability($pdo, (int) $admin["id"]);
    $today = availabilityNow($a);
    $from = $today->format("Y-m-d");
    $to = $today->modify("+" . min($windowDays, (int) $a["horizon_days"]) . " days")->format("Y-m-d");

    $slots = openSlots($pdo, (int) $admin["id"], $from, $to, (int) $a["slot_minutes"], $a);
    if (!$slots) {
        throw new PortalWriteError("You have no open times in that window. Add hours in Availability, or give them longer to pick.", 409);
    }

    $pdo->prepare("
        INSERT INTO booking_requests
            (admin_id, client, guest_name, guest_email, topic, notes, duration_min, timezone, window_from, window_to)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        (int) $admin["id"],
        $target["kind"] === "client" ? $target["company"] : null,
        $target["kind"] === "guest" ? $target["name"] : null,
        $target["kind"] === "guest" ? $target["email"] : null,
        $topic, $notes !== "" ? $notes : null,
        (int) $a["slot_minutes"], $a["timezone"], $from, $to,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    // Who gets a link: the guest, or every person who can sign in to each account.
    $people = [];
    if ($target["kind"] === "guest") {
        $people[] = ["account_id" => null, "name" => $target["name"], "email" => $target["email"]];
    } else {
        foreach ($target["accounts"] as $account) {
            foreach (clientAccountPeople($pdo, (int) $account["id"]) as $person) {
                if (filter_var((string) $person["email"], FILTER_VALIDATE_EMAIL)) {
                    $people[] = ["account_id" => (int) $account["id"], "name" => $person["name"], "email" => $person["email"]];
                }
            }
        }
    }

    $guestLink = null;
    foreach ($people as $person) {
        $guestLink = issueBookingLink($pdo, $requestId, $person);
    }

    if ($target["kind"] === "client") {
        foreach ($target["accounts"] as $account) {
            notifyClientAccount(
                $pdo,
                (int) $account["id"],
                NOTIFY_APPOINTMENT_REQUEST,
                $admin["name"] . " asked you to pick a meeting time",
                $topic,
                "dashboard.html",
                "admin",
                (int) $admin["id"]
            );
        }
    }

    $label = $target["kind"] === "client" ? $target["company"] : $target["name"] . " (guest)";
    logEvent($pdo, EVENT_BOOKING_SENT, "meeting", $requestId, $topic,
        "Asked " . $label . " to pick a time for \"" . $topic . "\" by " . $to,
        $target["kind"] === "client" && count($target["accounts"]) === 1 ? $target["accounts"][0]["id"] : null);

    return [
        "id" => $requestId,
        "link" => $target["kind"] === "guest" ? $guestLink : null,
        "slots" => count($slots),
        "sent_to" => count($people),
    ];
}

/** Stores a new link for one person and emails it. Returns the URL. */
function issueBookingLink(PDO $pdo, $requestId, array $person, $email = true)
{
    [$raw, $hash] = bookingToken();
    $pdo->prepare("
        INSERT INTO booking_request_links (booking_request_id, user_id, name, email, token_hash)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([(int) $requestId, $person["account_id"] ?: null, $person["name"] ?? null, $person["email"], $hash]);

    $url = bookingLinkUrl($raw);
    if ($email) {
        $request = bookingRequestById($pdo, $requestId);
        emailBookingInvite(
            ["name" => (string) ($person["name"] ?? ""), "email" => $person["email"]],
            (string) $request["host_name"],
            (string) $request["topic"],
            (string) ($request["notes"] ?? ""),
            $url,
            (string) $request["window_to"],
            (int) $request["duration_min"],
            (string) $request["timezone"]
        );
    }
    return $url;
}

function bookingRequestById(PDO $pdo, $id, $lock = false)
{
    $stmt = $pdo->prepare("
        SELECT r.*, a.name AS host_name
        FROM booking_requests r
        LEFT JOIN admins a ON a.id = r.admin_id
        WHERE r.id = ?" . ($lock ? " FOR UPDATE" : "")
    );
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * What a booking request's link should show:
 *   open | picked (waiting on the host) | booked (confirmed) | declined |
 *   expired | cancelled
 */
function bookingRequestState(PDO $pdo, array $request)
{
    if ($request["status"] === "cancelled") {
        return "cancelled";
    }
    if ((int) $request["appointment_id"] > 0) {
        $stmt = $pdo->prepare("SELECT status FROM appointments WHERE id = ?");
        $stmt->execute([(int) $request["appointment_id"]]);
        $status = (string) $stmt->fetchColumn();
        if ($status === "approved") {
            return "booked";
        }
        if ($status === "rejected") {
            return "declined";
        }
        if ($status === "cancelled") {
            return "cancelled";
        }
        return "picked";
    }
    $a = loadAvailability($pdo, (int) $request["admin_id"]);
    if ($request["window_to"] < availabilityNow($a)->format("Y-m-d")) {
        return "expired";
    }
    return "open";
}

/** The link and its request for a raw token, or null. */
function findBookingLink(PDO $pdo, $token)
{
    $token = (string) $token;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM booking_request_links WHERE token_hash = ? LIMIT 1");
    $stmt->execute([hash("sha256", $token)]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        return null;
    }
    $request = bookingRequestById($pdo, $link["booking_request_id"]);
    return $request ? ["link" => $link, "request" => $request] : null;
}

/**
 * Takes one of the host's open times for a booking request. $picker:
 *   account_id  the client account (null for a guest)
 *   person_id   the person picking, when they are signed in
 *   name, email the person (a guest may correct theirs)
 *   message     optional, for the host
 *   link_id     the link used, when it came from one
 *
 * Under a lock on the request and on the host, so the first pick wins and two
 * people never get the same time. Returns the new appointment id.
 */
function pickBookingSlot(PDO $pdo, $requestId, $date, $time, array $picker)
{
    $time = substr((string) $time, 0, 5);
    $message = mb_substr(trim((string) ($picker["message"] ?? "")), 0, 1000);

    // Read the host's settings once before the transaction: the first read of
    // a setting may create its table, and DDL would commit the transaction.
    $before = bookingRequestById($pdo, $requestId);
    if ($before) {
        loadAvailability($pdo, (int) $before["admin_id"]);
    }

    $pdo->beginTransaction();
    try {
        $request = bookingRequestById($pdo, $requestId, true);
        if (!$request) {
            throw new PortalWriteError("That booking link doesn't work any more.", 404);
        }
        // Serialises every pick for this host, whichever request it is for.
        $pdo->prepare("SELECT id FROM admins WHERE id = ? FOR UPDATE")->execute([(int) $request["admin_id"]]);

        $state = bookingRequestState($pdo, $request);
        if ($state !== "open") {
            throw new PortalWriteError([
                "picked" => "A time was already picked for this meeting.",
                "booked" => "This meeting is already booked.",
                "declined" => "This request is closed.",
                "expired" => "This link has expired.",
                "cancelled" => "This request was withdrawn.",
            ][$state], 409);
        }
        if ($date < $request["window_from"] || $date > $request["window_to"]
            || !slotIsOpen($pdo, (int) $request["admin_id"], $date, $time, (int) $request["duration_min"])) {
            throw new PortalWriteError("That time was just taken. Pick another one.", 409);
        }

        $isGuest = $request["guest_email"] !== null && $request["guest_email"] !== "";
        $a = loadAvailability($pdo, (int) $request["admin_id"]);
        $name = mb_substr(trim((string) ($picker["name"] ?? "")), 0, 150);
        $email = mb_substr(trim((string) ($picker["email"] ?? "")), 0, 190);

        $pdo->prepare("
            INSERT INTO appointments
                (user_id, title, date, time, status, topic, notes, requested_by, admin_id, client,
                 duration_min, timezone, guest_name, guest_email, source, booking_request_id, attendee_message,
                 requested_by_id, requested_by_name)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, 'user', ?, ?, ?, ?, ?, ?, 'booking', ?, ?, ?, ?)
        ")->execute([
            $isGuest ? null : (int) $picker["account_id"],
            $request["topic"], $date, $time . ":00", $request["topic"], $request["notes"],
            (int) $request["admin_id"],
            $isGuest ? null : $request["client"],
            (int) $request["duration_min"], $a["timezone"],
            $isGuest ? ($name ?: $request["guest_name"]) : null,
            $isGuest ? ($email ?: $request["guest_email"]) : null,
            (int) $request["id"],
            $message !== "" ? $message : null,
            !empty($picker["person_id"]) ? (int) $picker["person_id"] : null,
            $name !== "" ? $name : null,
        ]);
        $appointmentId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE booking_requests SET status = 'picked', appointment_id = ? WHERE id = ?")
            ->execute([$appointmentId, (int) $request["id"]]);
        if (!empty($picker["link_id"])) {
            $pdo->prepare("UPDATE booking_request_links SET used_at = NOW() WHERE id = ?")->execute([(int) $picker["link_id"]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // After the commit: notify() refuses to write inside a transaction.
    $who = $isGuest ? ($name ?: $request["guest_name"]) . " (guest)" : ($name !== "" ? $name . ", " : "") . $request["client"];
    $hosts = array_values(array_unique(array_merge([(int) $request["admin_id"]], ownerAdminIds($pdo))));
    foreach ($hosts as $hostId) {
        notify(
            $pdo, "admin", $hostId, NOTIFY_APPOINTMENT_REQUEST,
            $who . " picked a time",
            $request["topic"] . " - " . $date . " at " . $time,
            "admin-appointments.html?appointment=" . $appointmentId,
            $isGuest ? "" : "user",
            !empty($picker["person_id"]) ? (int) $picker["person_id"] : 0
        );
    }
    emailHostAboutPickedTime($pdo, [(int) $request["admin_id"]], $who, (string) $request["topic"], $date, $time,
        $a["timezone"], $message, $appointmentId);

    return $appointmentId;
}

/** The owner's admin id(s): they see every booking. */
function ownerAdminIds(PDO $pdo)
{
    try {
        return array_map("intval", $pdo->query("SELECT id FROM admins WHERE role = 'owner' AND active = 1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/** Whether $admin may manage this booking request: its host, or the owner's view. */
function bookingRequestInScope(PDO $pdo, array $admin, array $request)
{
    return (int) $request["admin_id"] === (int) $admin["id"] || can($admin, "clients.view_all", $pdo);
}

/**
 * Withdraws a booking request nobody has picked from: every link stops
 * working. Once a time is picked, that meeting is confirmed, rejected or
 * cancelled instead.
 */
function cancelBookingRequest(PDO $pdo, array $admin, $requestId)
{
    $request = bookingRequestById($pdo, $requestId);
    if (!$request || !bookingRequestInScope($pdo, $admin, $request)) {
        throw new PortalWriteError("That booking request is not yours.", 404);
    }
    $state = bookingRequestState($pdo, $request);
    if ($state === "picked") {
        throw new PortalWriteError("A time was already picked. Confirm or reject that meeting instead.", 409);
    }
    if ($state !== "open" && $state !== "expired") {
        throw new PortalWriteError("That booking request is already closed.", 409);
    }
    $pdo->prepare("UPDATE booking_requests SET status = 'cancelled' WHERE id = ? AND status = 'open'")->execute([(int) $requestId]);

    logEvent($pdo, EVENT_MEETING_CANCELLED, "meeting", $requestId, (string) $request["topic"],
        "Withdrew the booking request \"" . $request["topic"] . "\" for " . ($request["client"] ?: $request["guest_name"]), null);
}

/**
 * New links for a request still open: the old ones stop working. Emails them
 * unless $email is false (the page's "Copy new link", for a guest). Returns
 * the guest's new link, or null for a client request.
 */
function renewBookingLinks(PDO $pdo, array $admin, $requestId, $email = true)
{
    $request = bookingRequestById($pdo, $requestId);
    if (!$request || !bookingRequestInScope($pdo, $admin, $request)) {
        throw new PortalWriteError("That booking request is not yours.", 404);
    }
    if (bookingRequestState($pdo, $request) !== "open") {
        throw new PortalWriteError("Only an open booking request can be sent again.", 409);
    }

    $stmt = $pdo->prepare("SELECT * FROM booking_request_links WHERE booking_request_id = ?");
    $stmt->execute([(int) $requestId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->prepare("DELETE FROM booking_request_links WHERE booking_request_id = ?")->execute([(int) $requestId]);

    $last = null;
    foreach ($links as $link) {
        $last = issueBookingLink($pdo, $requestId, [
            "account_id" => $link["user_id"], "name" => $link["name"], "email" => $link["email"],
        ], $email);
    }
    return $request["guest_email"] ? $last : null;
}

/**
 * The booking requests on the Appointments page: the admin's own, or every
 * one for whoever sees every client. Open ones and recent closed ones.
 */
function adminBookingRequests(PDO $pdo, array $admin)
{
    $all = can($admin, "clients.view_all", $pdo);
    $stmt = $pdo->prepare("
        SELECT r.*, a.name AS host_name,
               (SELECT COUNT(*) FROM booking_request_links l WHERE l.booking_request_id = r.id) AS recipients,
               (SELECT COUNT(*) FROM booking_request_links l WHERE l.booking_request_id = r.id AND l.opened_at IS NOT NULL) AS opened
        FROM booking_requests r
        LEFT JOIN admins a ON a.id = r.admin_id
        WHERE (r.status = 'open' OR r.created_at > NOW() - INTERVAL 30 DAY)" . ($all ? "" : " AND r.admin_id = ?") . "
        ORDER BY r.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($all ? [] : [(int) $admin["id"]]);

    return array_map(function ($r) use ($pdo) {
        return [
            "id" => (int) $r["id"],
            "admin_id" => (int) $r["admin_id"],
            "host_name" => $r["host_name"],
            "client" => $r["client"],
            "guest_name" => $r["guest_name"],
            "guest_email" => $r["guest_email"],
            "topic" => $r["topic"],
            "notes" => $r["notes"],
            "duration_min" => (int) $r["duration_min"],
            "timezone" => $r["timezone"],
            "window_from" => $r["window_from"],
            "window_to" => $r["window_to"],
            "state" => bookingRequestState($pdo, $r),
            "appointment_id" => $r["appointment_id"] ? (int) $r["appointment_id"] : null,
            "recipients" => (int) $r["recipients"],
            "opened" => (int) $r["opened"],
            "created_at" => $r["created_at"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Open booking requests addressed to a client account's company. */
function clientBookingRequests(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.*, a.name AS host_name
        FROM booking_requests r
        JOIN booking_request_links l ON l.booking_request_id = r.id AND l.user_id = ?
        LEFT JOIN admins a ON a.id = r.admin_id
        WHERE r.status = 'open'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([(int) $accountId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (bookingRequestState($pdo, $r) === "open") {
            $out[] = $r;
        }
    }
    return $out;
}

/** What a booking page shows for a request: no host email, no other recipients. */
function bookingRequestPublic(PDO $pdo, array $request, ?array $link = null)
{
    $state = bookingRequestState($pdo, $request);
    $out = [
        "state" => $state,
        "host" => (string) $request["host_name"],
        "topic" => (string) $request["topic"],
        "notes" => (string) ($request["notes"] ?? ""),
        "duration_min" => (int) $request["duration_min"],
        "timezone" => loadAvailability($pdo, (int) $request["admin_id"])["timezone"],
        "window_to" => $request["window_to"],
        "company" => $request["client"],
        "slots" => $state === "open" ? bookingRequestSlots($pdo, $request) : [],
    ];
    if ($link) {
        $out["you"] = ["name" => (string) ($link["name"] ?? ""), "email" => (string) $link["email"], "guest" => !$link["user_id"]];
    }
    if ((int) $request["appointment_id"] > 0) {
        $stmt = $pdo->prepare("SELECT date, time, requested_by_name, guest_name FROM appointments WHERE id = ?");
        $stmt->execute([(int) $request["appointment_id"]]);
        if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out["picked"] = ["date" => $m["date"], "time" => substr((string) $m["time"], 0, 5),
                "by" => (string) ($m["requested_by_name"] ?: $m["guest_name"] ?: "")];
        }
    }
    return $out;
}
