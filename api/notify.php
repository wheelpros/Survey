<?php

/*
|--------------------------------------------------------------------------
| Notifications: writing them
|--------------------------------------------------------------------------
|
| Required by the endpoints where something notification-worthy happens.
| Reading them is api/notifications.php; the table is
| ensureNotificationsTable() in db.php, mirrored by sql/notifications.sql.
|
| Two rules for callers, both of which matter:
|
|   1. Call ensureNotificationsTable($pdo) once at the top of your file,
|      before any beginTransaction(). CREATE TABLE is DDL, and MySQL commits
|      implicitly around DDL even when the table already exists - ensuring it
|      from inside a transaction would silently commit the caller's work and
|      leave their rollBack() with nothing to roll back.
|
|   2. Emit AFTER commit(), never inside a transaction. Capture the ids you
|      need into locals while the transaction is open, then notify once it
|      has closed. Nothing here is worth rolling a form submission back over.
|
| Nothing in this file throws. A failed insert costs one row in a sidebar
| badge; the request that triggered it carries on untouched.
|
*/

require_once "db.php";

/* Event keys. Constants rather than loose strings so a typo is a PHP error
   at deploy time, not a row nobody can find later. */
const NOTIFY_FORM_AWAITING_REVIEW = "form_awaiting_review";
const NOTIFY_FORM_APPROVED        = "form_approved";
const NOTIFY_FORM_REJECTED        = "form_rejected";
const NOTIFY_FORM_SUBMITTED       = "form_submitted";
const NOTIFY_APPOINTMENT_REQUEST  = "appointment_request";
const NOTIFY_APPOINTMENT_ANSWERED = "appointment_answered";
const NOTIFY_CONTENT_PUBLISHED    = "content_published";
const NOTIFY_ANNOUNCEMENT         = "announcement";

/**
 * One row for one recipient. $kind is 'user' or 'admin', naming the table
 * $recipientId points at.
 */
function notify(
    PDO $pdo,
    string $kind,
    int $recipientId,
    string $type,
    string $title,
    string $body = "",
    string $link = "",
    string $actorKind = "",
    int $actorId = 0
) {
    if ($recipientId <= 0 || !in_array($kind, ["user", "admin"], true)) {
        return;
    }

    // Rule 2 above. Emitting here would tie the notification's fate to work
    // it has no business affecting, in either direction.
    if ($pdo->inTransaction()) {
        error_log("notify(): refused to emit '$type' from inside a transaction");
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications
                (recipient_kind, recipient_id, event_type,
                 title, body, link, actor_kind, actor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $kind,
            $recipientId,
            $type,
            mb_substr($title, 0, 200),
            $body !== "" ? mb_substr($body, 0, 500) : null,
            $link !== "" ? mb_substr($link, 0, 255) : null,
            in_array($actorKind, ["user", "admin"], true) ? $actorKind : null,
            $actorId > 0 ? $actorId : null,
        ]);
    } catch (Throwable $e) {
        // Read-only DB user, or the table was never created. The caller's own
        // work already succeeded, so say nothing.
    }
}

/**
 * Every admin responsible for this client, plus the owner.
 *
 * The inverse of isUserInScope() in admin-surveys.php and scopedClients() in
 * calendar.php: those ask "may this admin touch this user", this asks "which
 * admins should hear about this user". The owner is in unconditionally, on
 * the same reasoning that lets them see every client.
 *
 * $exceptAdminId drops whoever performed the action - nobody needs telling
 * about their own click.
 */
function notifyAdminsForUser(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $body = "",
    string $link = "",
    int $exceptAdminId = 0
) {
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id FROM admins WHERE role = 'owner'
            UNION
            SELECT admin_id AS id FROM admin_user_assignments WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    foreach ($ids as $id) {
        if ((int) $id === $exceptAdminId) {
            continue;
        }
        notify($pdo, "admin", (int) $id, $type, $title, $body, $link, "user", $userId);
    }
}

/**
 * The admins who sign forms off - the same roles admin-survey-review.php
 * lets through its guard, since that is the page they would land on.
 */
function notifyReviewers(
    PDO $pdo,
    string $type,
    string $title,
    string $body = "",
    string $link = "",
    int $exceptAdminId = 0
) {
    try {
        $stmt = $pdo->query("
            SELECT id FROM admins WHERE role IN ('account_manager', 'owner')
        ");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    foreach ($ids as $id) {
        if ((int) $id === $exceptAdminId) {
            continue;
        }
        notify($pdo, "admin", (int) $id, $type, $title, $body, $link);
    }
}

/**
 * Every approved client who would actually see this post on content.html.
 *
 * The audience test user-content.php applies in reverse: a blank client means
 * the post is global, so it goes to everyone; otherwise only the accounts
 * carrying that company name.
 *
 * This writes one row per recipient in a loop, which is the same shape as the
 * fan-out calendar.php already does when an admin requests a meeting from a
 * whole company. At a few hundred users that is fine; past a few thousand it
 * wants a batched multi-row INSERT.
 */
function notifyUsersAtCompany(
    PDO $pdo,
    ?string $company,
    string $type,
    string $title,
    string $body = "",
    string $link = "",
    int $actorAdminId = 0
) {
    $company = trim((string) $company);

    try {
        if ($company === "") {
            $stmt = $pdo->query("SELECT id FROM users WHERE approved = 1");
        } else {
            $stmt = $pdo->prepare("
                SELECT id FROM users WHERE approved = 1 AND company_name = ?
            ");
            $stmt->execute([$company]);
        }
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    foreach ($ids as $id) {
        notify($pdo, "user", (int) $id, $type, $title, $body, $link, "admin", $actorAdminId);
    }
}

/**
 * The fan-out for one hand-written announcement, to a recipient list the
 * caller has already resolved and checked.
 *
 * It owns its INSERT rather than calling notify() because these rows carry
 * announcement_id, and the other five callers have no use for that column.
 * $kind is 'user' or 'admin' for the whole batch - an announcement goes to
 * one audience, never to both at once.
 *
 * The body is only the preview line in the inbox; the full description, the
 * date and the image live on the announcements row the id points at.
 */
function notifyAnnouncement(
    PDO $pdo,
    string $kind,
    array $recipientIds,
    int $announcementId,
    string $title,
    string $body = "",
    int $actorAdminId = 0
) {
    if ($announcementId <= 0 || !in_array($kind, ["user", "admin"], true)) {
        return;
    }

    // Rule 2 at the top of this file.
    if ($pdo->inTransaction()) {
        error_log("notifyAnnouncement(): refused to emit from inside a transaction");
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications
                (recipient_kind, recipient_id, event_type,
                 title, body, link, actor_kind, actor_id, announcement_id)
            VALUES (?, ?, ?, ?, ?, NULL, 'admin', ?, ?)
        ");

        foreach ($recipientIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $stmt->execute([
                $kind,
                $id,
                NOTIFY_ANNOUNCEMENT,
                mb_substr($title, 0, 200),
                $body !== "" ? mb_substr($body, 0, 500) : null,
                $actorAdminId > 0 ? $actorAdminId : null,
                $announcementId,
            ]);
        }
    } catch (Throwable $e) {
        // Same silence as notify(): the announcement itself is already saved.
    }
}
