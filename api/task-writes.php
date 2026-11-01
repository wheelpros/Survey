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
require_once __DIR__ . "/events.php";

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
    if (can($admin, "tasks.write", $pdo)) {
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
    if (can($admin, "tasks.edit_any")) {
        return true;
    }

    return (int) $task["created_by_admin_id"] === (int) $admin["id"];
}

/**
 * Inserts ($existing null) or updates a task. $f holds the task's fields:
 * title, link, description (HTML), orientation, image_path, scheduled_date
 * and scheduled_time (already checked as dates/times, or null), status,
 * and gallery_paths (a list) when the caller changes the gallery.
 * is_complete is not among them: recomputeTaskComplete() derives it from the
 * Assigned table. Returns the task id.
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

    $columns = [
        "title"          => $title,
        "link"           => $link,
        "description"    => $description ?: null,
        "orientation"    => $orientation,
        "image_path"     => $f["image_path"] ?? null,
        "scheduled_date" => $f["scheduled_date"] ?? null,
        "scheduled_time" => $f["scheduled_time"] ?? null,
        "status"         => $status,
    ];

    if (array_key_exists("gallery_paths", $f)) {
        $gallery = array_values((array) $f["gallery_paths"]);
        $columns["gallery_paths"] = $gallery ? json_encode($gallery, JSON_UNESCAPED_SLASHES) : null;
    }

    /* The phase fields are written only when the caller sent them, so an
       api/v1 save - which knows nothing of them - leaves them as they were. */
    $columns += phaseFieldColumns($f);

    if ($existing) {
        $sets = implode(", ", array_map(function ($c) { return "`$c` = ?"; }, array_keys($columns)));
        $stmt = $pdo->prepare("UPDATE project_tasks SET $sets WHERE id = ?");
        $stmt->execute(array_merge(array_values($columns), [(int) $existing["id"]]));
        logEvent($pdo, EVENT_PHASE_UPDATED, "phase", $existing["id"], $title,
            "Edited phase \"" . $title . "\" on " . $project["title"], $project["client_id"] ?? null);
        return (int) $existing["id"];
    }

    $columns["project_id"]          = (int) $project["id"];
    $columns["created_by_admin_id"] = (int) $actor["id"];

    $names  = implode(", ", array_map(function ($c) { return "`$c`"; }, array_keys($columns)));
    $marks  = implode(", ", array_fill(0, count($columns), "?"));
    $stmt = $pdo->prepare("INSERT INTO project_tasks ($names) VALUES ($marks)");
    $stmt->execute(array_values($columns));

    $id = (int) $pdo->lastInsertId();
    logEvent($pdo, EVENT_PHASE_CREATED, "phase", $id, $title,
        "Added phase \"" . $title . "\" to " . $project["title"], $project["client_id"] ?? null);

    return $id;
}

/* Notes are a working space for the team, not an essay. */
const TASK_NOTES_MAX_CHARS = 5000;

/**
 * The phase fields present in $f, checked: phase_label and role (free text,
 * 80 characters), priority (TASK_PRIORITIES) and notes (Markdown text).
 */
function phaseFieldColumns(array $f)
{
    $out = [];

    foreach (["phase_label" => "Phase", "role" => "Role"] as $key => $label) {
        if (!array_key_exists($key, $f)) {
            continue;
        }
        $value = trim((string) $f[$key]);
        if (mb_strlen($value) > 80) {
            throw new PortalWriteError("$label must be 80 characters or fewer.");
        }
        $out[$key] = $value === "" ? null : $value;
    }

    if (array_key_exists("priority", $f)) {
        $out["priority"] = in_array($f["priority"], TASK_PRIORITIES, true) ? $f["priority"] : "medium";
    }

    if (array_key_exists("notes", $f)) {
        $notes = trim((string) $f["notes"]);
        if (mb_strlen($notes) > TASK_NOTES_MAX_CHARS) {
            throw new PortalWriteError("Notes must be " . TASK_NOTES_MAX_CHARS . " characters or fewer.");
        }
        $out["notes"] = $notes === "" ? null : $notes;
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| The people on a phase
|--------------------------------------------------------------------------
|
| Two lists, both drawn from the client's team - the same pool a content
| post picks from (contentAssigneeCandidates):
|
|   teammates     who is on the phase
|   assignments   which teammates worked on it, what they did, and how far
|                 they got - the rows the admin dashboard lists as "My tasks"
|
| Assignments are drawn from the teammates only, one row per person. Only
| someone who may edit the task changes either list, and never anyone's
| status: each person moves only their own, with no Save needed. The phase
| is complete once every assigned person is done (recomputeTaskComplete).
|
*/

require_once __DIR__ . "/content-writes.php";

/** Who can go on a phase of $project: [{id, name, email, role}], by name. */
function taskTeamCandidates(PDO $pdo, array $project)
{
    return contentAssigneeCandidates($pdo, $project["company_name"] ?? "");
}

/** The ids from $ids that may go on a phase: candidates, plus anyone already on it. */
function allowedTaskPeople(PDO $pdo, array $project, array $ids, array $current)
{
    $allowed = array_merge(
        array_column(taskTeamCandidates($pdo, $project), "id"),
        array_map("intval", $current)
    );

    return array_values(array_filter(
        array_unique(array_map("intval", $ids)),
        function ($id) use ($allowed) {
            return $id > 0 && in_array($id, $allowed, true);
        }
    ));
}

/** Teammates and assignments of a phase, with names and photos, for the pages. */
function taskTeamPayload(PDO $pdo, $taskId)
{
    ensureAdminColumns($pdo);

    $stmt = $pdo->prepare("
        SELECT m.admin_id AS id, a.name, a.role, a.avatar_path
        FROM project_task_teammates m
        LEFT JOIN admins a ON a.id = m.admin_id
        WHERE m.task_id = ?
        ORDER BY a.name ASC
    ");
    $stmt->execute([(int) $taskId]);
    $teammates = array_map(function ($r) {
        return ["id" => (int) $r["id"], "name" => (string) $r["name"], "role" => (string) $r["role"],
                "avatar_path" => $r["avatar_path"] ?: null];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT s.id, s.admin_id, a.name, a.role, s.work_note, s.status
        FROM project_task_assignments s
        LEFT JOIN admins a ON a.id = s.admin_id
        WHERE s.task_id = ?
        ORDER BY s.id ASC
    ");
    $stmt->execute([(int) $taskId]);
    $assignments = array_map(function ($r) {
        return ["id" => (int) $r["id"], "admin_id" => (int) $r["admin_id"],
                "name" => (string) $r["name"], "role" => (string) $r["role"],
                "work_note" => (string) $r["work_note"], "status" => (string) $r["status"]];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return ["teammates" => $teammates, "assignments" => $assignments];
}

/**
 * Puts exactly $ids on the phase as teammates. Boxes already ticked stay
 * ticked for the people who stay on. Returns the ids new to the list, for
 * notifyAddedToPhase().
 */
function saveTaskTeammates(PDO $pdo, array $project, $taskId, array $ids)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM project_task_teammates WHERE task_id = ?");
    $stmt->execute([(int) $taskId]);
    $before = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));

    $after   = allowedTaskPeople($pdo, $project, $ids, $before);
    $added   = array_values(array_diff($after, $before));
    $removed = array_values(array_diff($before, $after));

    if ($removed) {
        $marks = implode(",", array_fill(0, count($removed), "?"));
        $pdo->prepare("DELETE FROM project_task_teammates WHERE task_id = ? AND admin_id IN ($marks)")
            ->execute(array_merge([(int) $taskId], $removed));
    }

    $insert = $pdo->prepare("INSERT IGNORE INTO project_task_teammates (task_id, admin_id) VALUES (?, ?)");
    foreach ($added as $adminId) {
        $insert->execute([(int) $taskId, $adminId]);
    }

    /* Someone taken off the phase leaves the Assigned table with it. */
    if ($removed) {
        $marks = implode(",", array_fill(0, count($removed), "?"));
        $pdo->prepare("DELETE FROM project_task_assignments WHERE task_id = ? AND admin_id IN ($marks)")
            ->execute(array_merge([(int) $taskId], $removed));
        recomputeTaskComplete($pdo, $taskId);
    }

    if ($added || $removed) {
        [$title, $projectTitle, $clientId] = eventPhase($pdo, $taskId);
        logEvent($pdo, EVENT_PHASE_TEAM, "phase", $taskId, $title, "Changed the teammates on \"" . $title . "\""
            . ($added ? " - added " . eventAdminNames($pdo, $added) : "")
            . ($removed ? " - removed " . eventAdminNames($pdo, $removed) : ""), $clientId);
    }

    return $added;
}

/** The admin ids on the phase as teammates. */
function taskTeammateIds(PDO $pdo, $taskId)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM project_task_teammates WHERE task_id = ?");
    $stmt->execute([(int) $taskId]);

    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Makes the phase's assignments exactly $rows: [{admin_id, work_note}], one
 * per person, each a teammate on the phase (save the teammates first). A
 * person already assigned keeps their row and their status - only they move
 * it - and a person left out is removed. Anyone not a teammate is refused.
 * Returns the people new to the phase's assignments.
 */
function saveTaskAssignments(PDO $pdo, array $project, $taskId, array $rows)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM project_task_assignments WHERE task_id = ?");
    $stmt->execute([(int) $taskId]);
    $existing = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));

    $teammates = taskTeammateIds($pdo, $taskId);

    $notes = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $adminId = (int) ($row["admin_id"] ?? 0);
        if ($adminId <= 0 || isset($notes[$adminId])) {
            continue;
        }
        if (!in_array($adminId, $teammates, true)) {
            throw new PortalWriteError("Only teammates on this phase can be assigned. Add them to Teammates first.");
        }

        $note = trim((string) ($row["work_note"] ?? ""));
        if (mb_strlen($note) > 2000) {
            throw new PortalWriteError("What they did must be 2000 characters or fewer.");
        }
        $notes[$adminId] = $note;
    }

    $update = $pdo->prepare("UPDATE project_task_assignments SET work_note = ? WHERE task_id = ? AND admin_id = ?");
    $insert = $pdo->prepare("INSERT INTO project_task_assignments (task_id, admin_id, work_note, status) VALUES (?, ?, ?, 'todo')");

    $added = [];
    foreach ($notes as $adminId => $note) {
        if (in_array($adminId, $existing, true)) {
            $update->execute([$note ?: null, (int) $taskId, $adminId]);
        } else {
            $insert->execute([(int) $taskId, $adminId, $note ?: null]);
            $added[] = $adminId;
        }
    }

    $gone = array_values(array_diff($existing, array_keys($notes)));
    if ($gone) {
        $marks = implode(",", array_fill(0, count($gone), "?"));
        $pdo->prepare("DELETE FROM project_task_assignments WHERE task_id = ? AND admin_id IN ($marks)")
            ->execute(array_merge([(int) $taskId], $gone));
    }

    recomputeTaskComplete($pdo, $taskId);

    if ($added || $gone) {
        [$title, $projectTitle, $clientId] = eventPhase($pdo, $taskId);
        logEvent($pdo, EVENT_PHASE_TEAM, "phase", $taskId, $title, "Changed who is assigned to \"" . $title . "\""
            . ($added ? " - assigned " . eventAdminNames($pdo, $added) : "")
            . ($gone ? " - unassigned " . eventAdminNames($pdo, $gone) : ""), $clientId);
    }

    return $added;
}

/**
 * A phase is complete when it has at least one assigned person and every one
 * of them is done - nobody ticks the phase itself. Run after anything that
 * changes the Assigned table. Returns the new value.
 */
function recomputeTaskComplete(PDO $pdo, $taskId)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(status = 'done'), 0) AS done
        FROM project_task_assignments
        WHERE task_id = ?
    ");
    $stmt->execute([(int) $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $complete = (int) $row["total"] > 0 && (int) $row["done"] === (int) $row["total"] ? 1 : 0;

    $pdo->prepare("UPDATE project_tasks SET is_complete = ? WHERE id = ?")->execute([$complete, (int) $taskId]);

    return $complete;
}

/* Never inside a transaction (notify.php rule 2). One notification per
   person however many lists they were added to, and nobody is told about a
   phase they put themselves on. */
function notifyAddedToPhase(PDO $pdo, array $actor, array $project, $taskId, $title, array $adminIds)
{
    foreach (array_unique(array_map("intval", $adminIds)) as $adminId) {
        if ($adminId === (int) $actor["id"]) {
            continue;
        }
        notify(
            $pdo,
            "admin",
            (int) $adminId,
            NOTIFY_PROJECT_ASSIGNED,
            "You were added to a phase on " . $project["title"],
            (string) $title,
            "project-task-details.html?project=" . (int) $project["id"] . "&id=" . (int) $taskId,
            "admin",
            (int) $actor["id"]
        );
    }
}

/*
| Each person moves only their own row: the teammate box (set_done) and the
| assignment status (set_my_status). Shared by api/project-tasks.php and
| api/v1's set_my_phase_status. Both throw a 403 when the caller has no row
| on the phase - someone not on it has nothing to move.
*/
function setOwnPhaseDone(PDO $pdo, $taskId, $adminId, $done)
{
    $stmt = $pdo->prepare("SELECT 1 FROM project_task_teammates WHERE task_id = ? AND admin_id = ?");
    $stmt->execute([(int) $taskId, (int) $adminId]);
    if (!$stmt->fetchColumn()) {
        throw new PortalWriteError("Only a teammate on this phase can tick their box.", 403);
    }

    $done = $done ? 1 : 0;
    $stmt = $pdo->prepare("
        UPDATE project_task_teammates
        SET is_done = ?, done_at = IF(? = 1, NOW(), NULL)
        WHERE task_id = ? AND admin_id = ?
    ");
    $stmt->execute([$done, $done, (int) $taskId, (int) $adminId]);

    [$title, $projectTitle, $clientId] = eventPhase($pdo, $taskId);
    logEvent($pdo, EVENT_PHASE_STATUS, "phase", $taskId, $title,
        ($done ? "Ticked their box on \"" : "Unticked their box on \"") . $title . "\"", $clientId);

    return $done;
}

function setOwnPhaseStatus(PDO $pdo, $taskId, $adminId, $status)
{
    if (!array_key_exists((string) $status, TASK_ASSIGNMENT_STATUSES)) {
        throw new PortalWriteError("Pick To do, In progress or Done.");
    }

    $stmt = $pdo->prepare("SELECT 1 FROM project_task_assignments WHERE task_id = ? AND admin_id = ?");
    $stmt->execute([(int) $taskId, (int) $adminId]);
    if (!$stmt->fetchColumn()) {
        throw new PortalWriteError("Only someone assigned to this phase can change their status.", 403);
    }

    $stmt = $pdo->prepare("UPDATE project_task_assignments SET status = ? WHERE task_id = ? AND admin_id = ?");
    $stmt->execute([$status, (int) $taskId, (int) $adminId]);

    recomputeTaskComplete($pdo, $taskId);

    [$title, $projectTitle, $clientId] = eventPhase($pdo, $taskId);
    logEvent($pdo, EVENT_PHASE_STATUS, "phase", $taskId, $title,
        "Set their status on \"" . $title . "\" to " . TASK_ASSIGNMENT_STATUSES[$status], $clientId);

    return $status;
}

/**
 * Deletes a phase with its people, attachments and files. The caller has
 * checked canEditTask(). Shared by project-tasks.php's DELETE and api/v1.
 */
function deleteProjectTask(PDO $pdo, array $task)
{
    require_once __DIR__ . "/upload-helpers.php";

    $id = (int) $task["id"];

    [$title, $projectTitle, $clientId] = eventPhase($pdo, $id);
    $pdo->prepare("DELETE FROM project_tasks WHERE id = ?")->execute([$id]);
    logEvent($pdo, EVENT_PHASE_DELETED, "phase", $id, $title,
        "Deleted phase \"" . $title . "\"" . ($projectTitle !== "" ? " from " . $projectTitle : ""), $clientId);
    $pdo->prepare("DELETE FROM project_task_teammates WHERE task_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM project_task_assignments WHERE task_id = ?")->execute([$id]);
    $files = deleteAttachmentRows($pdo, "task", $id);

    deleteUploadedFiles(array_merge(
        $files,
        array_filter([$task["image_path"] ?? null]),
        taskGallery($task)
    ));
}

/**
 * The caller's own assignments on phases of projects they can see, with the
 * phase and project each belongs to, plus the counts by status and how many
 * projects they can see. admin-dashboard.php and api/v1's list_my_phases.
 */
function myPhaseAssignments(PDO $pdo, array $actor)
{
    [$scopeSql, $scopeParams] = projectScopeSql($actor);
    $scope = $scopeSql !== "" ? " AND " . $scopeSql : "";

    $stmt = $pdo->prepare("
        SELECT
            s.id          AS assignment_id,
            s.status,
            s.work_note,
            t.id          AS task_id,
            t.title,
            t.scheduled_date,
            t.phase_label,
            p.id          AS project_id,
            p.title       AS project_title
        FROM project_task_assignments s
        JOIN project_tasks t ON t.id = s.task_id
        JOIN projects p      ON p.id = t.project_id
        WHERE s.admin_id = ?" . $scope . "
        ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, s.id ASC
    ");
    $stmt->execute(array_merge([(int) $actor["id"]], $scopeParams));
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = array_fill_keys(array_keys(TASK_ASSIGNMENT_STATUSES), 0);
    foreach ($tasks as $task) {
        if (isset($counts[$task["status"]])) {
            $counts[$task["status"]]++;
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE 1 = 1" . $scope);
    $stmt->execute($scopeParams);

    return ["tasks" => $tasks, "counts" => $counts, "projects" => (int) $stmt->fetchColumn()];
}

/**
 * Everything on the admin dashboard's "My tasks", as one list. Each item has
 * a `kind` and a `status` of todo, in_progress or done:
 *
 *   assignment  a phase I am assigned to (project_task_assignments): all
 *               three statuses, moved with set_my_status
 *   teammate    a phase I am a teammate on but not assigned to: todo or done,
 *               my own box (project-tasks.php set_done)
 *   content     a content post I am a teammate on (content_assignees): todo or
 *               done, my own box (admin-content.php set_done)
 *
 * A phase I am both assigned to and a teammate on shows once, as the
 * assignment. Phases follow projectScopeSql(), as everywhere; a post is mine
 * by being on it. Returns the items soonest-due first, the counts by status
 * over all of them, and how many projects I can see.
 */
function myDashboardItems(PDO $pdo, array $actor)
{
    $mine = myPhaseAssignments($pdo, $actor);

    $items = [];

    foreach ($mine["tasks"] as $r) {
        $items[] = [
            "kind" => "assignment",
            "id" => (int) $r["task_id"],
            "assignment_id" => (int) $r["assignment_id"],
            "title" => (string) $r["title"],
            "chip" => (string) $r["project_title"],
            "due_date" => $r["scheduled_date"],
            "label" => $r["phase_label"],
            "status" => (string) $r["status"],
            "href" => "project-task-details.html?project=" . (int) $r["project_id"] . "&id=" . (int) $r["task_id"],
        ];
    }

    [$scopeSql, $scopeParams] = projectScopeSql($actor);
    $scope = $scopeSql !== "" ? " AND " . $scopeSql : "";

    $stmt = $pdo->prepare("
        SELECT t.id AS task_id, t.title, t.scheduled_date, t.phase_label, m.is_done,
               p.id AS project_id, p.title AS project_title
        FROM project_task_teammates m
        JOIN project_tasks t ON t.id = m.task_id
        JOIN projects p      ON p.id = t.project_id
        WHERE m.admin_id = ?" . $scope . "
          AND NOT EXISTS (
              SELECT 1 FROM project_task_assignments s
              WHERE s.task_id = t.id AND s.admin_id = m.admin_id
          )
    ");
    $stmt->execute(array_merge([(int) $actor["id"]], $scopeParams));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            "kind" => "teammate",
            "id" => (int) $r["task_id"],
            "title" => (string) $r["title"],
            "chip" => (string) $r["project_title"],
            "due_date" => $r["scheduled_date"],
            "label" => $r["phase_label"],
            "status" => (int) $r["is_done"] === 1 ? "done" : "todo",
            "href" => "project-task-details.html?project=" . (int) $r["project_id"] . "&id=" . (int) $r["task_id"],
        ];
    }

    // A database without the content tables costs the dashboard its posts, not itself.
    try {
        ensureContentColumns($pdo);

        $stmt = $pdo->prepare("
            SELECT c.id, c.title, c.client, c.type_label, c.content_type, c.post_date, a.is_done, a.status
            FROM content_assignees a
            JOIN content c ON c.id = a.content_id
            WHERE a.admin_id = ?
        ");
        $stmt->execute([(int) $actor["id"]]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                "kind" => "content",
                "id" => (int) $r["id"],
                "title" => (string) $r["title"],
                "chip" => trim((string) $r["client"]) !== "" ? (string) $r["client"] : "Content",
                "due_date" => $r["post_date"],
                "label" => $r["type_label"] ?: $r["content_type"],
                // A post's Assigned row has the same three steps as a phase's.
                "status" => array_key_exists((string) $r["status"], TASK_ASSIGNMENT_STATUSES)
                    ? (string) $r["status"]
                    : ((int) $r["is_done"] === 1 ? "done" : "todo"),
                "href" => "admin-content-form.html?id=" . (int) $r["id"],
            ];
        }
    } catch (Throwable $e) {
        // Left out.
    }

    // Soonest due first, undated last; ties keep the order they were found in.
    foreach ($items as $i => &$item) {
        $item["order"] = $i;
    }
    unset($item);

    usort($items, function ($a, $b) {
        $da = $a["due_date"] ?: "9999-12-31";
        $db = $b["due_date"] ?: "9999-12-31";
        return $da === $db ? $a["order"] <=> $b["order"] : strcmp($da, $db);
    });

    $counts = array_fill_keys(array_keys(TASK_ASSIGNMENT_STATUSES), 0);
    foreach ($items as &$item) {
        unset($item["order"]);
        if (isset($counts[$item["status"]])) {
            $counts[$item["status"]]++;
        }
    }
    unset($item);

    return ["tasks" => $items, "counts" => $counts, "projects" => $mine["projects"]];
}

/**
 * The Team overview for the Director: every person's row in an Assigned
 * table - phase assignments inside projectScopeSql() (all of them for the
 * Director), and post assignees - each with who it is, its status and
 * whether it is overdue.
 * Plus the counts (with overdue), how many projects, and what needs
 * attention: phases nobody is assigned to (they can never complete), forms
 * waiting for review, and meeting requests waiting for a reply.
 *
 * Read-only: each person moves only their own status, elsewhere.
 */
function teamDashboardItems(PDO $pdo, array $actor)
{
    ensureAdminColumns($pdo);

    [$scopeSql, $scopeParams] = projectScopeSql($actor);
    $scope = $scopeSql !== "" ? " AND " . $scopeSql : "";
    $today = date("Y-m-d");

    $person = function ($r) {
        return ["id" => (int) $r["admin_id"], "name" => (string) $r["admin_name"], "avatar_path" => $r["avatar_path"] ?: null];
    };

    $items = [];

    $stmt = $pdo->prepare("
        SELECT s.status, s.admin_id, a.name AS admin_name, a.avatar_path,
               t.id AS task_id, t.title, t.scheduled_date, t.phase_label,
               p.id AS project_id, p.title AS project_title
        FROM project_task_assignments s
        JOIN project_tasks t ON t.id = s.task_id
        JOIN projects p      ON p.id = t.project_id
        LEFT JOIN admins a   ON a.id = s.admin_id
        WHERE 1 = 1" . $scope . "
    ");
    $stmt->execute($scopeParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            "kind" => "assignment",
            "id" => (int) $r["task_id"],
            "person" => $person($r),
            "title" => (string) $r["title"],
            "chip" => (string) $r["project_title"],
            "due_date" => $r["scheduled_date"],
            "label" => $r["phase_label"],
            "status" => (string) $r["status"],
            "href" => "project-task-details.html?project=" . (int) $r["project_id"] . "&id=" . (int) $r["task_id"],
        ];
    }

    try {
        ensureContentColumns($pdo);
        $stmt = $pdo->query("
            SELECT ca.admin_id, ca.status, ca.is_done, a.name AS admin_name, a.avatar_path,
                   c.id, c.title, c.client, c.type_label, c.content_type, c.post_date
            FROM content_assignees ca
            JOIN content c     ON c.id = ca.content_id
            LEFT JOIN admins a ON a.id = ca.admin_id
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                "kind" => "content",
                "id" => (int) $r["id"],
                "person" => $person($r),
                "title" => (string) $r["title"],
                "chip" => trim((string) $r["client"]) !== "" ? (string) $r["client"] : "Content",
                "due_date" => $r["post_date"],
                "label" => $r["type_label"] ?: $r["content_type"],
                "status" => array_key_exists((string) $r["status"], TASK_ASSIGNMENT_STATUSES)
                    ? (string) $r["status"]
                    : ((int) $r["is_done"] === 1 ? "done" : "todo"),
                "href" => "admin-content-form.html?id=" . (int) $r["id"],
            ];
        }
    } catch (Throwable $e) {
        // A database without the content tables costs the overview its posts.
    }

    // Soonest due first, undated last; then by person.
    usort($items, function ($a, $b) {
        $da = $a["due_date"] ?: "9999-12-31";
        $db = $b["due_date"] ?: "9999-12-31";
        return $da === $db ? strcmp($a["person"]["name"], $b["person"]["name"]) : strcmp($da, $db);
    });

    $counts = array_fill_keys(array_keys(TASK_ASSIGNMENT_STATUSES), 0) + ["overdue" => 0];
    foreach ($items as &$item) {
        $item["overdue"] = $item["status"] !== "done" && $item["due_date"] && substr((string) $item["due_date"], 0, 10) < $today;
        if (isset($counts[$item["status"]])) {
            $counts[$item["status"]]++;
        }
        if ($item["overdue"]) {
            $counts["overdue"]++;
        }
    }
    unset($item);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE 1 = 1" . $scope);
    $stmt->execute($scopeParams);
    $projects = (int) $stmt->fetchColumn();

    return [
        "tasks" => $items,
        "counts" => $counts,
        "projects" => $projects,
        "attention" => teamAttention($pdo, $actor, $scope, $scopeParams),
    ];
}

/**
 * What the Team overview flags: phases with nobody assigned (each with a
 * link - they can never complete), how many forms wait for review, and how
 * many client meeting requests wait for a reply, from the clients in scope.
 */
function teamAttention(PDO $pdo, array $actor, $scope, array $scopeParams)
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, p.id AS project_id, p.title AS project_title
        FROM project_tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE NOT EXISTS (SELECT 1 FROM project_task_assignments s WHERE s.task_id = t.id)" . $scope . "
        ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id ASC
    ");
    $stmt->execute($scopeParams);
    $unassigned = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "project" => (string) $r["project_title"],
            "href" => "project-task-details.html?project=" . (int) $r["project_id"] . "&id=" . (int) $r["id"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // The review queue: both roles review every form (admin-survey-review.php).
    $forms = 0;
    try {
        $forms = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'pending_review'")->fetchColumn();
    } catch (Throwable $e) {
    }

    // Meeting requests a client sent and nobody answered: every client for
    // the Director, an account manager's own clients otherwise.
    $meetings = 0;
    try {
        if (can($actor, "clients.view_all", $pdo)) {
            $meetings = (int) $pdo->query("
                SELECT COUNT(*) FROM appointments WHERE status = 'pending' AND requested_by = 'user'
            ")->fetchColumn();
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM appointments ap
                JOIN admin_user_assignments aua ON aua.user_id = ap.user_id AND aua.admin_id = ?
                WHERE ap.status = 'pending' AND ap.requested_by = 'user'
            ");
            $stmt->execute([(int) $actor["id"]]);
            $meetings = (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    return [
        "unassigned_phases" => $unassigned,
        "forms_to_review" => ["count" => $forms, "href" => "admin.html"],
        "meetings_waiting" => ["count" => $meetings, "href" => "admin-calendar.html"],
    ];
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
