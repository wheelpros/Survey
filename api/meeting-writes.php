<?php

/*
|--------------------------------------------------------------------------
| Meetings: W|ZONE asking a client for time, and answering a client's ask
|--------------------------------------------------------------------------
|
| Shared by calendar.php (admin-appointments.html) and the AI-facing api/v1, so
| a meeting an assistant proposes reaches the same people, in the portal and
| by email, as one sent from the page. calendar.php's header explains the
| `appointments` row and its requested_by column.
|
| $admin is the acting admin: ["id", "name", "role"]. Refusals throw
| PortalWriteError (db.php). Callers run ensureAppointmentTables() and
| ensureNotificationsTable() first.
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/ics.php";

/**
 * The client accounts a request for $company goes to: every account the
 * admin may reach that carries that company name. The admin picks a
 * company, not a person - the same choice content posts offer - and each
 * account answers for its own diary.
 */
function meetingTargetsForCompany(PDO $pdo, array $admin, $company)
{
    $company = trim((string) $company);
    if ($company === "") {
        return [];
    }

    // Compared in PHP, exactly - SQL's collation would also match "acme ltd".
    return array_values(array_filter(meetingScopedClients($pdo, $admin), function ($row) use ($company) {
        return trim((string) ($row["company_name"] ?? "")) === $company;
    }));
}

/**
 * The clients an admin may act on: every client for the owner, otherwise
 * the ones assigned to them - Clients Management's rule.
 */
function meetingScopedClients(PDO $pdo, array $admin)
{
    if (can($admin, "clients.view_all", $pdo)) {
        return $pdo->query("
            SELECT id, name, email, company_name, approved
            FROM users
            WHERE account_owner_id IS NULL
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name, u.approved
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ? AND u.account_owner_id IS NULL
        ORDER BY u.name ASC
    ");
    $stmt->execute([$admin["id"]]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Asks each of $targets (rows from meetingTargetsForCompany) for a meeting:
 * one pending row each, a notification each, and an email each. Returns
 * the new appointment ids.
 */
function requestMeetingFromClients(PDO $pdo, array $admin, array $targets, $company, $date, $time, $topic, $notes)
{
    $topic = mb_substr(trim((string) $topic), 0, 200);
    $notes = trim((string) $notes);

    if (!$date || !$time || $topic === "") {
        throw new PortalWriteError("Pick a date and time, and say what the meeting is about.");
    }
    if (!$targets) {
        throw new PortalWriteError("No client accounts carry that company name.", 404);
    }

    $insert = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, notes, requested_by, admin_id, client)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, 'admin', ?, ?)
    ");

    $ids = [];

    foreach ($targets as $target) {
        $insert->execute([
            $target["id"],
            $topic,
            $date,
            $time,
            $topic,
            $notes !== "" ? $notes : null,
            $admin["id"],
            $company
        ]);
        $ids[] = (int) $pdo->lastInsertId();

        // One row per account, and everyone on it is told.
        notifyClientAccount(
            $pdo,
            (int) $target["id"],
            NOTIFY_APPOINTMENT_REQUEST,
            "Meeting request from " . $admin["name"],
            $topic . " - " . $date . " at " . $time,
            "dashboard.html",
            "admin",
            (int) $admin["id"]
        );
    }

    logEvent($pdo, EVENT_MEETING_PROPOSED, "meeting", $ids[0] ?? 0, $topic,
        "Proposed a meeting with " . $company . ": \"" . $topic . "\" on " . $date . " at " . $time,
        count($targets) === 1 ? $targets[0]["id"] : null);

    // Each client by email too: the request needs their answer, and the
    // portal only shows it to someone who happens to sign in.
    $people = [];
    foreach ($targets as $target) {
        $people = array_merge($people, clientAccountPeople($pdo, $target["id"]));
    }

    [$hostName, $hostEmail] = meetingOrganizer($pdo, (int) $admin["id"], $admin);
    emailClientsAboutMeetingRequest($people, $hostName ?: (string) $admin["name"], $topic, $date, $time, $notes,
        ["name" => $hostName, "email" => $hostEmail]);

    return $ids;
}

/**
 * Whether $admin may act on this meeting row: a client's meeting when the
 * client is theirs to reach; a guest's (no user_id) when they host it or see
 * every client.
 */
function meetingInAdminScope(PDO $pdo, array $admin, array $row)
{
    [$sql, $params] = meetingVisibilitySql($pdo, $admin, "a");
    $stmt = $pdo->prepare("SELECT 1 FROM appointments a WHERE a.id = ? AND $sql LIMIT 1");
    $stmt->execute(array_merge([(int) ($row["id"] ?? 0)], $params));
    return (bool) $stmt->fetchColumn();
}

/**
 * Which meetings an admin sees and acts on, as [sql, params] over the
 * appointments alias $a. The same rule for every role, the Director
 * included: meetings with the clients assigned to them, and meetings they
 * host (a client's or a guest's). Booking is wider - meetingScopedClients()
 * still lets the Director book with any client - but other people's
 * meetings stay theirs.
 *
 * So no request goes unseen, whoever sees every client also gets a client's
 * unanswered request (no host yet) when nobody is assigned to that client.
 */
function meetingVisibilitySql(PDO $pdo, array $admin, $a = "a")
{
    $id = (int) $admin["id"];
    $sql = "($a.user_id IN (SELECT user_id FROM admin_user_assignments WHERE admin_id = ?) OR $a.admin_id = ?";
    $params = [$id, $id];

    if (can($admin, "clients.view_all", $pdo)) {
        $sql .= " OR ($a.admin_id IS NULL AND $a.user_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM admin_user_assignments x WHERE x.user_id = $a.user_id))";
    }

    return [$sql . ")", $params];
}

/** The staff member who owns a meeting, as [name, email] for invites. */
function meetingOrganizer(PDO $pdo, $adminId, array $fallback)
{
    if ((int) $adminId > 0) {
        $stmt = $pdo->prepare("SELECT name, email FROM admins WHERE id = ?");
        $stmt->execute([(int) $adminId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [(string) $row["name"], (string) $row["email"]];
        }
    }
    return [(string) ($fallback["name"] ?? ""), (string) ($fallback["email"] ?? "")];
}

/**
 * Who hears about a meeting by email: everyone on the client's account, or
 * the guest. Returns [people, isGuest].
 */
function meetingAttendees(PDO $pdo, array $row)
{
    if ((int) ($row["user_id"] ?? 0) > 0) {
        return [clientAccountPeople($pdo, (int) $row["user_id"]), false];
    }
    $email = trim((string) ($row["guest_email"] ?? ""));
    return [$email !== "" ? [["name" => (string) ($row["guest_name"] ?? ""), "email" => $email, "approved" => 1]] : [], true];
}

/**
 * Tells the client (in the portal) and every attendee (by email) what
 * happened to a meeting. $outcome: confirmed | booked | declined | cancelled.
 * Confirmed, booked and cancelled meetings carry a calendar invite.
 */
function tellMeetingAttendees(PDO $pdo, array $admin, array $row, $outcome, $note = "")
{
    $topic = (string) ($row["topic"] ?: ($row["title"] ?? "") ?: "Meeting");

    if ((int) ($row["user_id"] ?? 0) > 0) {
        $titles = [
            "confirmed" => "Your meeting was confirmed",
            "booked" => "A meeting was booked with you",
            "declined" => "Your meeting request was declined",
            "cancelled" => "A meeting was cancelled",
        ];
        notifyClientAccount(
            $pdo,
            (int) $row["user_id"],
            NOTIFY_APPOINTMENT_ANSWERED,
            $titles[$outcome],
            $topic . " - " . $row["date"] . " at " . substr((string) $row["time"], 0, 5),
            "dashboard.html",
            "admin",
            (int) $admin["id"]
        );
    }

    // The meeting's host - whoever acted when it has none yet. Emails go out
    // in their name, and replies reach them.
    $organizer = meetingOrganizer($pdo, (int) ($row["admin_id"] ?? 0) ?: (int) $admin["id"], $admin);
    $host = ["name" => $organizer[0] ?: (string) $admin["name"], "email" => $organizer[1]];

    [$people, $guest] = meetingAttendees($pdo, $row);
    $attachments = [];
    if (in_array($outcome, ["confirmed", "booked", "cancelled"], true)) {
        $attendees = array_map(function ($p) { return [(string) ($p["name"] ?? ""), (string) ($p["email"] ?? "")]; }, $people);
        $attachments[] = meetingIcsAttachment($row, $organizer, $attendees, $outcome === "cancelled" ? "CANCEL" : "REQUEST");
    }

    emailClientsAboutMeetingOutcome(
        $people,
        $host["name"],
        $outcome,
        $topic,
        (string) $row["date"],
        (string) $row["time"],
        (string) $note,
        ["guest" => $guest, "timezone" => (string) ($row["timezone"] ?? ""), "attachments" => $attachments, "sender" => $host]
    );

    // The host's copy, with the same invite, so it is in their calendar too.
    // A declined request leaves nothing to put there.
    if ($attachments) {
        emailHostMeetingCopy($host, $outcome, meetingWhoName($pdo, $row), $topic, (string) $row["date"], (string) $row["time"],
            (string) ($row["timezone"] ?? ""), (int) ($row["id"] ?? 0), $attachments);
    }
}

/** Who a meeting is with, for the host's emails: the client's company, or the guest. */
function meetingWhoName(PDO $pdo, array $row)
{
    if ((int) ($row["user_id"] ?? 0) > 0) {
        return clientLabel($pdo, (int) $row["user_id"]) ?: "a client";
    }
    return trim((string) ($row["guest_name"] ?? "")) ?: "a guest";
}

const MEETING_ROW_COLUMNS = "id, user_id, title, topic, notes, status, date, time, requested_by, admin_id,
    duration_min, timezone, guest_name, guest_email, source, booking_request_id";

/**
 * Accepts ('approved') or declines ('rejected') a request waiting on the team:
 * one a client sent, or a time someone picked from a booking link. Only one
 * the admin may reach. $onlyIfPending refuses one already answered - api/v1
 * sets it, so an assistant never overturns a decision someone made in the
 * meantime; the page may change its mind.
 *
 * Returns the appointment as it was: ["user_id", "topic", "status", ...].
 */
function answerClientMeetingRequest(PDO $pdo, array $admin, $appointmentId, $status, $onlyIfPending = false)
{
    if (!in_array($status, ["approved", "rejected"], true)) {
        throw new PortalWriteError("Invalid status");
    }

    $stmt = $pdo->prepare("
        SELECT " . MEETING_ROW_COLUMNS . "
        FROM appointments
        WHERE id = ? AND requested_by = 'user'
        LIMIT 1
    ");
    $stmt->execute([(int) $appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !meetingInAdminScope($pdo, $admin, $row)) {
        throw new PortalWriteError("That request is not yours to answer.", 404);
    }
    if ($onlyIfPending && $row["status"] !== "pending") {
        throw new PortalWriteError("That request has already been answered.", 409);
    }
    // A cancelled meeting stays cancelled: reviving it would surprise a client
    // who was already told it is off.
    if ($row["status"] === "cancelled") {
        throw new PortalWriteError("That meeting was cancelled.", 409);
    }

    // A booked time keeps its host; a client's ask belongs to whoever answers.
    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = ?, admin_id = COALESCE(admin_id, ?)
        WHERE id = ?
    ");
    $stmt->execute([$status, $admin["id"], (int) $appointmentId]);
    if (!$row["admin_id"]) {
        $row["admin_id"] = $admin["id"];
    }

    // The booking request it came from is done either way.
    if ((int) $row["booking_request_id"] > 0) {
        $pdo->prepare("UPDATE booking_requests SET status = 'closed' WHERE id = ?")
            ->execute([(int) $row["booking_request_id"]]);
    }

    $who = (int) $row["user_id"] > 0 ? eventClientName($pdo, $row["user_id"]) : ($row["guest_name"] ?: "a guest");
    logEvent($pdo, EVENT_MEETING_ANSWERED, "meeting", $appointmentId, (string) ($row["topic"] ?? ""),
        ($status === "approved" ? "Accepted " : "Declined ") . $who
        . "'s meeting request \"" . ($row["topic"] ?? "Meeting") . "\" on " . $row["date"] . " at " . $row["time"],
        (int) $row["user_id"] > 0 ? $row["user_id"] : null);

    // In the portal every time; by email only when the answer changed, so
    // re-saving the same decision sends nobody a second copy.
    if ($row["status"] !== $status) {
        tellMeetingAttendees($pdo, $admin, $row, $status === "approved" ? "confirmed" : "declined");
    } elseif ((int) $row["user_id"] > 0) {
        notifyClientAccount(
            $pdo,
            (int) $row["user_id"],
            NOTIFY_APPOINTMENT_ANSWERED,
            $status === "approved" ? "Your meeting was confirmed" : "Your meeting request was declined",
            (string) ($row["topic"] ?? "Meeting"),
            "dashboard.html",
            "admin",
            (int) $admin["id"]
        );
    }

    return $row;
}

/**
 * Calls a meeting off: a confirmed one, or one still waiting on an answer
 * (a client's ask, a picked time, or a request the team sent). Only one the
 * admin may reach. The row stays, marked 'cancelled', so both calendars can
 * still say what happened; the attendees are told in the portal and by email,
 * with a cancellation for their calendar when the meeting had been on.
 *
 * $reason is optional and goes to them as written.
 * Returns the appointment as it was.
 */
function cancelMeeting(PDO $pdo, array $admin, $appointmentId, $reason = "")
{
    $reason = mb_substr(trim((string) $reason), 0, 500);

    $stmt = $pdo->prepare("SELECT " . MEETING_ROW_COLUMNS . " FROM appointments WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !meetingInAdminScope($pdo, $admin, $row)) {
        throw new PortalWriteError("That meeting is not yours to cancel.", 404);
    }
    if (!in_array($row["status"], ["pending", "approved"], true)) {
        throw new PortalWriteError(
            $row["status"] === "cancelled" ? "That meeting is already cancelled." : "A declined meeting has nothing to cancel.",
            409
        );
    }

    // Only while it is still in that state, so two people cancelling at once
    // send one email between them.
    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = 'cancelled'
        WHERE id = ? AND status = ?
    ");
    $stmt->execute([(int) $appointmentId, $row["status"]]);

    if ($stmt->rowCount() === 0) {
        throw new PortalWriteError("That meeting changed in the meantime. Reload and try again.", 409);
    }

    if ((int) $row["booking_request_id"] > 0) {
        $pdo->prepare("UPDATE booking_requests SET status = 'closed' WHERE id = ?")
            ->execute([(int) $row["booking_request_id"]]);
    }

    $topic = (string) ($row["topic"] ?: "Meeting");
    $who = (int) $row["user_id"] > 0 ? eventClientName($pdo, $row["user_id"]) : ($row["guest_name"] ?: "a guest");

    logEvent($pdo, EVENT_MEETING_CANCELLED, "meeting", $appointmentId, $topic,
        "Cancelled the meeting with " . $who . " \"" . $topic . "\" on " . $row["date"] . " at " . $row["time"],
        (int) $row["user_id"] > 0 ? $row["user_id"] : null);

    tellMeetingAttendees($pdo, $admin, $row, "cancelled", $reason);

    return $row;
}

/*
|--------------------------------------------------------------------------
| The client's side
|--------------------------------------------------------------------------
|
| dashboard.html's "Send a new request" and its accept / decline buttons,
| shared with api/v1. $user is the person asking, with their account (withClientAccount()).
*/

/**
 * A client asks the W|ZONE team for a meeting: one pending row, and every
 * admin responsible for them told in the portal and by email. Returns the
 * appointment id.
 */
function requestMeetingFromAdmins(PDO $pdo, array $user, $date, $time, $topic, $notes)
{
    $topic = mb_substr(trim((string) $topic), 0, 200);
    $notes = trim((string) $notes);

    if (!$date || !$time || $topic === "") {
        throw new PortalWriteError("Pick a date and time, and say what the meeting is about.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, notes, requested_by, client, requested_by_id, requested_by_name)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, 'user', ?, ?, ?)
    ");
    $stmt->execute([
        $user["account_id"],
        $topic,
        $date,
        $time,
        $topic,
        $notes !== "" ? $notes : null,
        $user["company_name"] ?? null,
        $user["id"],
        $user["name"]
    ]);

    $appointmentId = (int) $pdo->lastInsertId();

    // Opens this request on the calendar, not just the calendar.
    notifyAdminsAboutUser(
        $pdo,
        meetingAdminIdsForUser($pdo, (int) $user["account_id"]),
        (int) $user["id"],
        NOTIFY_APPOINTMENT_REQUEST,
        "Meeting request from " . $user["name"],
        $topic . " - " . $date . " at " . $time,
        "admin-appointments.html?appointment=" . $appointmentId
    );

    // The same admins, by email: a request left unseen in the portal is a
    // client left waiting for an answer.
    emailAdminsAboutMeetingRequest($pdo, (int) $user["account_id"], (string) $user["name"], $topic, $date, $time, $notes, $appointmentId);

    return $appointmentId;
}

/**
 * A client accepts ('approved') or declines ('rejected') a meeting W|ZONE
 * asked them for. requested_by is checked so a client can't answer their own
 * request - that one is the admin team's to decide. $onlyIfPending as in
 * answerClientMeetingRequest(). Returns the appointment as it was.
 */
function answerAdminMeetingRequest(PDO $pdo, array $user, $appointmentId, $status, $onlyIfPending = false)
{
    if (!in_array($status, ["approved", "rejected"], true)) {
        throw new PortalWriteError("Invalid status");
    }

    // Read before writing, so the admin who asked can be told the answer.
    $lookup = $pdo->prepare("
        SELECT admin_id, topic, status, date, time
        FROM appointments
        WHERE id = ? AND user_id = ? AND requested_by = 'admin'
        LIMIT 1
    ");
    $lookup->execute([(int) $appointmentId, $user["account_id"]]);
    $request = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new PortalWriteError("That meeting request isn't yours to answer.", 404);
    }
    // The first answer stands, for everyone on the account: of two at the same
    // moment the second finds nothing still pending.
    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = ?, responded_by_id = ?, responded_by_name = ?, responded_at = NOW()
        WHERE id = ? AND user_id = ? AND requested_by = 'admin' AND status = 'pending'
    ");
    $stmt->execute([$status, $user["id"], $user["name"], (int) $appointmentId, $user["account_id"]]);

    if ($stmt->rowCount() === 0) {
        throw new PortalWriteError("That meeting request has already been answered.", 409);
    }

    if ((int) $request["admin_id"] > 0) {
        notify(
            $pdo,
            "admin",
            (int) $request["admin_id"],
            NOTIFY_APPOINTMENT_ANSWERED,
            $user["name"] . ($status === "approved" ? " confirmed your meeting" : " declined your meeting"),
            (string) ($request["topic"] ?? "Meeting"),
            "admin-appointments.html?appointment=" . (int) $appointmentId,
            "user",
            (int) $user["id"]
        );

        // And by email: an accepted meeting comes with its calendar invite,
        // so it lands in the host's calendar.
        $stmt = $pdo->prepare("SELECT " . MEETING_ROW_COLUMNS . " FROM appointments WHERE id = ?");
        $stmt->execute([(int) $appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        [$hostName, $hostEmail] = meetingOrganizer($pdo, (int) $request["admin_id"], []);
        if ($row && $hostEmail !== "") {
            $attachments = [];
            if ($status === "approved") {
                [$people] = meetingAttendees($pdo, $row);
                $attendees = array_map(function ($p) { return [(string) ($p["name"] ?? ""), (string) ($p["email"] ?? "")]; }, $people);
                $attachments[] = meetingIcsAttachment($row, [$hostName, $hostEmail], $attendees, "REQUEST");
            }
            emailHostMeetingCopy(["name" => $hostName, "email" => $hostEmail], $status === "approved" ? "accepted" : "declined_by_client",
                meetingWhoName($pdo, $row), (string) ($row["topic"] ?: $row["title"] ?: "Meeting"), (string) $row["date"], (string) $row["time"],
                (string) ($row["timezone"] ?? ""), (int) $row["id"], $attachments);
        }
    }

    return $request;
}
