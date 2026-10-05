<?php

/*
|--------------------------------------------------------------------------
| Meetings: W|ZONE asking a client for time, and answering a client's ask
|--------------------------------------------------------------------------
|
| Shared by calendar.php (admin-calendar.html) and the AI-facing api/v1, so
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
    if (($admin["role"] ?? "") === "owner") {
        return $pdo->query("
            SELECT id, name, email, company_name, approved
            FROM users
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name, u.approved
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ?
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

        // One notification per account, matching the one row each of them got.
        notify(
            $pdo,
            "user",
            (int) $target["id"],
            NOTIFY_APPOINTMENT_REQUEST,
            "Meeting request from " . $admin["name"],
            $topic . " - " . $date . " at " . $time,
            "dashboard.html",
            "admin",
            (int) $admin["id"]
        );
    }

    // Each client by email too: the request needs their answer, and the
    // portal only shows it to someone who happens to sign in.
    emailClientsAboutMeetingRequest($targets, (string) $admin["name"], $topic, $date, $time, $notes);

    return $ids;
}

/**
 * Accepts ('approved') or declines ('rejected') a request a client sent.
 * Only one from a client the admin may reach. $onlyIfPending refuses one
 * already answered - api/v1 sets it, so an assistant never overturns a
 * decision someone made in the meantime; the page may change its mind.
 *
 * Returns the appointment as it was: ["user_id", "topic", "status", ...].
 */
function answerClientMeetingRequest(PDO $pdo, array $admin, $appointmentId, $status, $onlyIfPending = false)
{
    if (!in_array($status, ["approved", "rejected"], true)) {
        throw new PortalWriteError("Invalid status");
    }

    $stmt = $pdo->prepare("
        SELECT user_id, topic, status, date, time
        FROM appointments
        WHERE id = ? AND requested_by = 'user'
        LIMIT 1
    ");
    $stmt->execute([(int) $appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $allowed = array_map("intval", array_column(meetingScopedClients($pdo, $admin), "id"));

    if (!$row || !in_array((int) $row["user_id"], $allowed, true)) {
        throw new PortalWriteError("That request is not yours to answer.", 404);
    }
    if ($onlyIfPending && $row["status"] !== "pending") {
        throw new PortalWriteError("That request has already been answered.", 409);
    }

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = ?, admin_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $admin["id"], (int) $appointmentId]);

    notify(
        $pdo,
        "user",
        (int) $row["user_id"],
        NOTIFY_APPOINTMENT_ANSWERED,
        $status === "approved" ? "Your meeting was confirmed" : "Your meeting request was declined",
        (string) ($row["topic"] ?? "Meeting"),
        "dashboard.html",
        "admin",
        (int) $admin["id"]
    );

    return $row;
}
