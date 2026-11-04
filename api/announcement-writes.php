<?php

/*
|--------------------------------------------------------------------------
| Announcements: the shared checks, sending and reading
|--------------------------------------------------------------------------
|
| Used by api/announcements.php (the form and the detail panel) and api/v1
| (the MCP), so an announcement is checked, addressed and fanned out the
| same way from either. Sending needs announcements.send; the caller checks
| it. Only the page can attach an image.
|
| The caller has run ensureAnnouncementsTable() first (DDL commits
| implicitly, and notifyAnnouncement() refuses to run in a transaction).
|
*/

require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/mailer.php"; // APP_URL, for image links

const ANNOUNCEMENT_TITLE_MAX = 200;
const ANNOUNCEMENT_BODY_MAX = 5000;

/**
 * The announcement's own fields, checked: [$title, $description, $eventDate|null].
 * Throws PortalWriteError (400).
 */
function checkAnnouncement($audience, $scope, $title, $description, $eventDate)
{
    if (!in_array($audience, ["team", "client"], true)) {
        throw new PortalWriteError("Choose a team or a client announcement.", 400);
    }
    if (!in_array($scope, ["all", "specific"], true)) {
        throw new PortalWriteError("Choose who this goes to.", 400);
    }

    $title = trim((string) $title);
    $description = trim((string) $description);
    $eventDate = trim((string) $eventDate);

    if ($title === "") {
        throw new PortalWriteError("Give the announcement a title.", 400);
    }
    if (mb_strlen($title) > ANNOUNCEMENT_TITLE_MAX) {
        throw new PortalWriteError("Title is too long (" . ANNOUNCEMENT_TITLE_MAX . " characters max).", 400);
    }
    if (mb_strlen($description) > ANNOUNCEMENT_BODY_MAX) {
        throw new PortalWriteError("Description is too long (" . ANNOUNCEMENT_BODY_MAX . " characters max).", 400);
    }
    if ($eventDate !== "") {
        $parsed = DateTime::createFromFormat("Y-m-d", $eventDate);
        if (!$parsed || $parsed->format("Y-m-d") !== $eventDate) {
            throw new PortalWriteError("Enter a valid date.", 400);
        }
    }

    return [$title, $description, $eventDate !== "" ? $eventDate : null];
}

/**
 * Who receives it: active admins other than the sender for "team", approved
 * clients for "client" - everyone, or the $posted ids that are one of those.
 * An id that is neither simply does not survive the lookup. Throws
 * PortalWriteError when nobody is left.
 */
function announcementRecipientIds(PDO $pdo, $senderId, $audience, $scope, array $posted = [])
{
    if ($scope === "all") {
        if ($audience === "team") {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE active = 1 AND id <> ?");
            $stmt->execute([(int) $senderId]);
        } else {
            $stmt = $pdo->query("SELECT id FROM users WHERE approved = 1");
        }
        $ids = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $posted = array_values(array_unique(array_filter(array_map("intval", $posted), function ($id) {
            return $id > 0;
        })));
        if (!$posted) {
            throw new PortalWriteError("Pick at least one person.", 400);
        }

        $marks = implode(",", array_fill(0, count($posted), "?"));
        if ($audience === "team") {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE active = 1 AND id <> ? AND id IN ($marks)");
            $stmt->execute(array_merge([(int) $senderId], $posted));
        } else {
            // Staff pick an account, and everyone on it gets the announcement.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE approved = 1 AND (id IN ($marks) OR account_owner_id IN ($marks))");
            $stmt->execute(array_merge($posted, $posted));
        }
        $ids = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$ids) {
        throw new PortalWriteError("Nobody to send this to.", 400);
    }
    return $ids;
}

/**
 * Saves the announcement, logs it, then fans it out to $recipientIds - never
 * the other way round, and never inside a transaction. Returns its id.
 * Throws PortalWriteError (500) when it could not be saved.
 */
function createAnnouncement(PDO $pdo, $senderId, $audience, array $recipientIds, $title, $description, $eventDate, $imagePath = null)
{
    try {
        $pdo->prepare("
            INSERT INTO announcements
                (audience, title, body, event_date, image_path, created_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $audience,
            $title,
            $description !== "" ? $description : null,
            $eventDate,
            $imagePath,
            (int) $senderId,
        ]);

        $id = (int) $pdo->lastInsertId();

        logEvent($pdo, EVENT_ANNOUNCEMENT_SENT, "announcement", $id, $title,
            "Sent announcement \"" . $title . "\" to " . $audience);
    } catch (PDOException $e) {
        throw new PortalWriteError("Could not save the announcement.", 500);
    }

    // The inbox line is a preview; the detail panel shows the description in full.
    $preview = $description !== ""
        ? mb_substr(preg_replace('/\s+/u', " ", $description), 0, 160)
        : "";

    notifyAnnouncement($pdo, $audience === "team" ? "admin" : "user", $recipientIds, $id,
        $title, $preview, (int) $senderId);

    return $id;
}

/**
 * One announcement for someone who received it ($kind "admin"/"user", $id)
 * or the admin who wrote it; null otherwise, so nothing confirms it exists.
 * The recipient predicate is the authorisation, as for marking read.
 */
function announcementFor(PDO $pdo, $kind, $id, $announcementId)
{
    $announcementId = (int) $announcementId;
    if ($announcementId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE announcement_id = ? AND recipient_kind = ? AND recipient_id = ?
    ");
    $stmt->execute([$announcementId, $kind, (int) $id]);
    $isRecipient = (int) $stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("
        SELECT a.id, a.audience, a.title, a.body, a.event_date, a.image_path,
               a.created_by_admin_id, a.created_at,
               UNIX_TIMESTAMP(a.created_at) AS created_ts,
               admins.name AS created_by_name
        FROM announcements a
        LEFT JOIN admins ON admins.id = a.created_by_admin_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$announcementId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $isAuthor = $kind === "admin" && (int) $row["created_by_admin_id"] === (int) $id;

    return $isRecipient || $isAuthor ? $row : null;
}

/** An announcementFor() row as api/v1 returns it: the text is what an admin typed. */
function announcementOut(array $row)
{
    return [
        "id" => (int) $row["id"],
        "audience" => (string) $row["audience"],
        "event_date" => $row["event_date"] === null || $row["event_date"] === "" ? null : (string) $row["event_date"],
        "image_url" => $row["image_path"] ? APP_URL . "/" . $row["image_path"] : null,
        "created_by_name" => $row["created_by_name"] === null ? null : (string) $row["created_by_name"],
        "created_at" => (string) $row["created_at"],
        "untrusted_content" => [
            "title" => (string) $row["title"],
            "description" => (string) ($row["body"] ?? ""),
        ],
    ];
}
