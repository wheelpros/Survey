<?php

/*
|--------------------------------------------------------------------------
| Content: who may do what with a post
|--------------------------------------------------------------------------
|
| Shared by admin-content.php and the AI-facing api/v1, so an assistant
| editing a draft is held to exactly the rules the content pages follow.
|
| A post works like a task. The owner, the account managers and the super
| admins create it, often with nothing but a title, and pick the teammates -
| the client's team - who work on it with them (content_assignees).
|
| The owner and the account managers run the posting calendar: they see every
| post, and they alone schedule and publish. Anyone else sees the posts they
| created and the ones they are assigned to, and can fill those in. The
| author, the owner and the account managers change who is on a post and
| delete it; an assignee does neither.
|
| Those are the defaults: the role lists are permissions now (content.create,
| content.publish, content.view_all in api/permissions.php), which the
| Director can change.
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/events.php";

function canPublishContent($admin)
{
    return can($admin, "content.publish");
}

function canCreateContent($admin)
{
    return can($admin, "content.create");
}

function canViewAllContent($admin)
{
    return contentSeesAll($admin);
}

function isContentAssignee(PDO $pdo, $contentId, $adminId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM content_assignees
            WHERE content_id = ? AND admin_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $contentId, (int) $adminId]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function canEditContent($admin, $creatorId, $assigned = false)
{
    if (canViewAllContent($admin) || $assigned) {
        return true;
    }

    return (int) $creatorId === (int) $admin["id"];
}

function canAssignContent($admin, $creatorId)
{
    if (canPublishContent($admin)) {
        return true;
    }

    return (int) $creatorId === (int) $admin["id"];
}

function canDeleteContent($admin, $creatorId)
{
    return canAssignContent($admin, $creatorId);
}

/*
|--------------------------------------------------------------------------
| The team on a post
|--------------------------------------------------------------------------
|
| A post is worked on by its client's team: the account managers, Super
| Admins and Admins assigned to any account at the client's company
| (admin_user_assignments - client-team.php teamMembers()). A post with no
| client yet can take anyone in CONTENT_ASSIGNEE_ROLES.
|
*/

/** Who can be put on a post for $company: [{id, name, email, role}], by name. */
function contentAssigneeCandidates(PDO $pdo, $company)
{
    $company = trim((string) $company);
    $roles = implode(",", array_fill(0, count(CONTENT_ASSIGNEE_ROLES), "?"));
    ensureAdminColumns($pdo);

    if ($company === "") {
        $stmt = $pdo->prepare("
            SELECT id, name, email, role, avatar_path
            FROM admins
            WHERE active = 1 AND role IN ($roles)
            ORDER BY name ASC
        ");
        $stmt->execute(CONTENT_ASSIGNEE_ROLES);
    } else {
        ensureUserProfileColumns($pdo);
        $stmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.name, a.email, a.role, a.avatar_path
            FROM admin_user_assignments aua
            JOIN admins a ON a.id = aua.admin_id
            JOIN users u ON u.id = aua.user_id
            WHERE u.company_name = ? AND a.active = 1 AND a.role IN ($roles)
            ORDER BY a.name ASC
        ");
        $stmt->execute(array_merge([$company], CONTENT_ASSIGNEE_ROLES));
    }

    return array_map(function ($r) {
        return ["id" => (int) $r["id"], "name" => (string) $r["name"],
                "email" => (string) $r["email"], "role" => (string) $r["role"],
                "avatar_path" => $r["avatar_path"] ?: null];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * The ids from $ids that may be on a post for $company, in the order given:
 * its candidates, plus anyone already on it ($current) - someone taken off
 * the client's team since is kept rather than silently dropped. Never the
 * person saving: they are on the post already, or its author.
 */
function allowedContentAssignees(PDO $pdo, array $ids, $company, array $current, $actorId)
{
    $allowed = array_merge(
        array_column(contentAssigneeCandidates($pdo, $company), "id"),
        array_map("intval", $current)
    );

    return array_values(array_filter(
        array_unique(array_map("intval", $ids)),
        function ($id) use ($allowed, $actorId) {
            return $id > 0 && $id !== (int) $actorId && in_array($id, $allowed, true);
        }
    ));
}

/**
 * Puts exactly $after on the post, then tells each teammate who was not on it
 * before. Run after the post itself is saved; a failure here leaves the post
 * saved and the team as it was, which the next save can put right.
 */
function saveContentAssignees(PDO $pdo, $contentId, array $before, array $after, $title, $actorId)
{
    $added = array_values(array_diff($after, $before));
    $removed = array_values(array_diff($before, $after));

    if (!$added && !$removed) {
        return;
    }

    try {
        $pdo->beginTransaction();

        if ($removed) {
            $placeholders = implode(",", array_fill(0, count($removed), "?"));

            $stmt = $pdo->prepare("
                DELETE FROM content_assignees
                WHERE content_id = ? AND admin_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([(int) $contentId], $removed));
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO content_assignees (content_id, admin_id) VALUES (?, ?)
        ");

        foreach ($added as $adminId) {
            $stmt->execute([(int) $contentId, (int) $adminId]);
        }

        [, $clientId] = eventContent($pdo, $contentId);
        logEvent($pdo, EVENT_CONTENT_ASSIGNEES, "content", $contentId, $title, "Changed the team on \"" . $title . "\""
            . ($added ? " - added " . eventAdminNames($pdo, $added) : "")
            . ($removed ? " - removed " . eventAdminNames($pdo, $removed) : ""), $clientId);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return;
    }

    // Outside the transaction, per rule 2 in notify.php. Only the people new
    // to the post hear about it - the ones already on it were told before.
    foreach ($added as $adminId) {
        notify(
            $pdo,
            "admin",
            (int) $adminId,
            NOTIFY_CONTENT_ASSIGNED,
            "You were assigned a content task",
            (string) $title,
            "admin-content.html?id=" . (int) $contentId,
            "admin",
            (int) $actorId
        );
    }
}

/**
 * Ticks (or unticks) the caller's own box on a post - the teammate checklist
 * on admin-content-form.html, the admin dashboard and api/v1's
 * set_my_post_done. Only their own content_assignees row changes; someone not
 * on the post has no box (403). Returns 1 or 0.
 */
function setOwnContentDone(PDO $pdo, $contentId, $adminId, $done)
{
    $stmt = $pdo->prepare("SELECT id FROM content WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $contentId]);

    if (!$stmt->fetch()) {
        throw new PortalWriteError("Content not found.", 404);
    }

    if (!isContentAssignee($pdo, $contentId, $adminId)) {
        throw new PortalWriteError("Only a teammate assigned to this post can tick their box.", 403);
    }

    $done = $done ? 1 : 0;

    // The box is the status now: ticked is done, unticked is to do.
    setOwnContentStatus($pdo, $contentId, $adminId, $done ? "done" : "todo");

    return $done;
}

/**
 * Sets what each teammate does on the post, from $notes: admin_id => text.
 * Only people on the post are touched; anyone else in the map is ignored,
 * since the team itself is saved by saveContentAssignees(). Status is not
 * set here - each person moves their own (setOwnContentStatus).
 */
function saveContentWorkNotes(PDO $pdo, $contentId, array $notes)
{
    $onPost = currentAssigneeIds($pdo, $contentId);

    $stmt = $pdo->prepare("UPDATE content_assignees SET work_note = ? WHERE content_id = ? AND admin_id = ?");

    foreach ($notes as $adminId => $note) {
        $adminId = (int) $adminId;
        if (!in_array($adminId, $onPost, true)) {
            continue;
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 2000) {
            throw new PortalWriteError("What they do must be 2000 characters or fewer.");
        }
        $stmt->execute([$note !== "" ? $note : null, (int) $contentId, $adminId]);
    }
}

/**
 * Moves the caller's own row on a post to $status (TASK_ASSIGNMENT_STATUSES),
 * keeping is_done in step. Throws a 403 when the caller is not on the post.
 * Shared by admin-content.php (set_my_status, and set_done) and api/v1.
 */
function setOwnContentStatus(PDO $pdo, $contentId, $adminId, $status)
{
    if (!array_key_exists((string) $status, TASK_ASSIGNMENT_STATUSES)) {
        throw new PortalWriteError("Pick To do, In progress or Done.");
    }
    if (!isContentAssignee($pdo, $contentId, $adminId)) {
        throw new PortalWriteError("Only a teammate assigned to this post can change their status.", 403);
    }

    $done = $status === "done" ? 1 : 0;
    $stmt = $pdo->prepare("
        UPDATE content_assignees
        SET status = ?, is_done = ?, done_at = IF(? = 1, COALESCE(done_at, NOW()), NULL)
        WHERE content_id = ? AND admin_id = ?
    ");
    $stmt->execute([$status, $done, $done, (int) $contentId, (int) $adminId]);

    [$title, $clientId] = eventContent($pdo, $contentId);
    logEvent($pdo, EVENT_CONTENT_STATUS, "content", $contentId, $title,
        "Set their status on \"" . $title . "\" to " . TASK_ASSIGNMENT_STATUSES[$status], $clientId);

    return $status;
}

/** The admin ids on a post now. */
function currentAssigneeIds(PDO $pdo, $contentId)
{
    try {
        $stmt = $pdo->prepare("SELECT admin_id FROM content_assignees WHERE content_id = ?");
        $stmt->execute([(int) $contentId]);

        return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| When a post goes out
|--------------------------------------------------------------------------
|
| Publishing now clears the date and time. A scheduled post needs both, and
| one whose time has already passed goes live now and keeps its date, so the
| Content pages and the calendar show the day it was meant for - publishing
| here, rather than waiting for a read to notice, is also what sends the
| client their notification.
|
| Returns [status, date, time]; throws PortalWriteError when a schedule has
| no date or time.
|
*/
function resolveContentSchedule($status, $publishNow, $postDate, $postTime)
{
    if ($publishNow) {
        return ["published", null, null];
    }

    if ($status === "scheduled") {
        if (!$postDate || !$postTime) {
            throw new PortalWriteError("Post date and time are required.");
        }

        $scheduledAt = strtotime($postDate . " " . $postTime);

        if ($scheduledAt !== false && $scheduledAt <= time()) {
            $status = "published";
        }
    }

    return [$status, $postDate, $postTime];
}
