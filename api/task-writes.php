<?php

/*
|--------------------------------------------------------------------------
| Project tasks: saving an update, and telling the client once it's live
|--------------------------------------------------------------------------
|
| Shared by project-tasks.php (project-task-form.html) and the AI-facing
| api/v1, so an update an assistant posts is held to the same team rules,
| the same limits, and announces itself to the client exactly once - the
| same way as one written on the page.
|
| $actor is the acting admin: ["id", "role"]. Refusals throw
| PortalWriteError (db.php). Callers run ensureProjectTables() and
| ensureNotificationsTable() first.
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";

/* The rich-text counter under the editor says 0 / 2000, and it counts text,
   not the markup the editor wraps around it. */
const TASK_DESCRIPTION_MAX_CHARS = 2000;

/*
| Null when the project does not exist OR is out of scope. The caller answers
| 404 either way, so nothing confirms the existence of a project this admin
| cannot reach.
*/
function loadScopedProject(PDO $pdo, array $admin, $projectId)
{
    [$scopeSql, $scopeParams] = projectScopeSql($admin);

    $sql = "
        SELECT
            p.id,
            p.title,
            p.client_id,
            p.account_manager_admin_id,
            p.created_by_admin_id,
            u.company_name,
            u.name AS client_name
        FROM projects p
        LEFT JOIN users u ON u.id = p.client_id
        WHERE p.id = ?
    ";
    $params = [(int) $projectId];

    if ($scopeSql !== "") {
        $sql .= " AND " . $scopeSql;
        $params = array_merge($params, $scopeParams);
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/*
|--------------------------------------------------------------------------
| Who may write a task
|--------------------------------------------------------------------------
|
| Wider than creating a project, because writing updates is the day job of the
| people put on one. An seo_admin gets in only through membership - being able
| to see a project through a client assignment does not make you part of the
| team working on it.
|
*/
function canWriteTask(PDO $pdo, array $admin, array $project)
{
    if (in_array($admin["role"] ?? "", ["owner", "account_manager", "super_admin"], true)) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1 FROM project_members WHERE project_id = ? AND admin_id = ? LIMIT 1
    ");
    $stmt->execute([(int) $project["id"], (int) $admin["id"]]);

    return (bool) $stmt->fetch();
}

/*
| Editing somebody else's task is a narrower thing than writing your own: the
| owner and the account managers, or the author. An seo_admin cannot rewrite a
| teammate's update.
*/
function canEditTask(array $admin, array $task)
{
    if (in_array($admin["role"] ?? "", ["owner", "account_manager"], true)) {
        return true;
    }

    return (int) $task["created_by_admin_id"] === (int) $admin["id"];
}

/**
 * Inserts ($existing null) or updates a task. $f holds the task's fields:
 * title, link, description (HTML), orientation, image_path, scheduled_date
 * and scheduled_time (already checked as dates/times, or null),
 * is_complete, status. Returns the task id.
 */
function saveProjectTask(PDO $pdo, array $actor, array $project, $existing, array $f)
{
    if (!canWriteTask($pdo, $actor, $project)) {
        throw new PortalWriteError("You are not on this project's team.", 403);
    }
    if ($existing && !canEditTask($actor, $existing)) {
        throw new PortalWriteError("You can only edit your own tasks.", 403);
    }

    $title = trim((string) ($f["title"] ?? ""));
    if ($title === "") {
        throw new PortalWriteError("Task name is required.");
    }
    if (mb_strlen($title) > 200) {
        throw new PortalWriteError("Task name must be 200 characters or fewer.");
    }

    /* Printed as an href on the card, so only http(s) - the same rule as
       content links. */
    $link = normalisePortalLink($f["link"] ?? "");

    /* The editor stores HTML but the counter under it counts text, so the cap
       is checked against the text the same way the page measures it. */
    $description = trim((string) ($f["description"] ?? ""));
    $plain = trim(html_entity_decode(strip_tags($description), ENT_QUOTES, "UTF-8"));
    if (mb_strlen($plain) > TASK_DESCRIPTION_MAX_CHARS) {
        throw new PortalWriteError("Description must be " . TASK_DESCRIPTION_MAX_CHARS . " characters or fewer.");
    }

    $orientation = in_array($f["orientation"] ?? "", ["horizontal", "vertical"], true) ? $f["orientation"] : "horizontal";

    /* draft or published, nothing else - anything unrecognised is pinned to
       draft server-side rather than trusted. */
    $status = in_array($f["status"] ?? "", ["draft", "published"], true) ? $f["status"] : "draft";

    $values = [
        $title,
        $link,
        $description ?: null,
        $orientation,
        $f["image_path"] ?? null,
        $f["scheduled_date"] ?? null,
        $f["scheduled_time"] ?? null,
        !empty($f["is_complete"]) ? 1 : 0,
        $status,
    ];

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE project_tasks SET
                title          = ?,
                link           = ?,
                description    = ?,
                orientation    = ?,
                image_path     = ?,
                scheduled_date = ?,
                scheduled_time = ?,
                is_complete    = ?,
                status         = ?
            WHERE id = ?
        ");
        $stmt->execute(array_merge($values, [(int) $existing["id"]]));
        return (int) $existing["id"];
    }

    $stmt = $pdo->prepare("
        INSERT INTO project_tasks (
            title,
            link,
            description,
            orientation,
            image_path,
            scheduled_date,
            scheduled_time,
            is_complete,
            status,
            project_id,
            created_by_admin_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(array_merge($values, [(int) $project["id"], (int) $actor["id"]]));

    return (int) $pdo->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| Tell the client an update landed
|--------------------------------------------------------------------------
|
| Fires when the task is live now - the same TASK_IS_LIVE_SQL the client
| endpoint filters on - and has never been announced. The stamp is what
| makes it happen once: comparing the old status with the new one would
| re-announce on every later edit.
|
| The UPDATE is the test. Claiming the row and reading rowCount() means two
| concurrent saves cannot both decide they were first, and reusing the
| constant means the announcement can never disagree with what the client
| can actually see.
|
| The gap, stated plainly: nothing runs on a schedule here, so a task
| published for a future date is not announced on the day it goes live. It
| announces itself on the next save that finds it live, and otherwise the
| client meets it on the page. No notification is lost, only late.
|
| Never inside a transaction (notify.php rule 2). Returns whether it sent.
*/
function announceTaskIfLive(PDO $pdo, array $actor, array $project, $taskId, $title)
{
    try {
        $stmt = $pdo->prepare("
            UPDATE project_tasks t
            SET t.client_notified_at = NOW()
            WHERE t.id = ?
              AND " . TASK_IS_LIVE_SQL . "
              AND t.client_notified_at IS NULL
        ");
        $stmt->execute([(int) $taskId]);

        if ($stmt->rowCount() > 0) {
            notify(
                $pdo,
                "user",
                (int) $project["client_id"],
                NOTIFY_PROJECT_UPDATE,
                "New update on " . $project["title"],
                $title,
                "user-project-details.html?id=" . (int) $project["id"],
                "admin",
                (int) $actor["id"]
            );
            return true;
        }
    } catch (Throwable $e) {
        // Same silence as notify() itself: the task is already saved, and a
        // missing client_notified_at column costs a sidebar badge, nothing more.
    }

    return false;
}
