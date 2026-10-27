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

    $columns = [
        "title"          => $title,
        "link"           => $link,
        "description"    => $description ?: null,
        "orientation"    => $orientation,
        "image_path"     => $f["image_path"] ?? null,
        "scheduled_date" => $f["scheduled_date"] ?? null,
        "scheduled_time" => $f["scheduled_time"] ?? null,
        "is_complete"    => !empty($f["is_complete"]) ? 1 : 0,
        "status"         => $status,
    ];

    /* The phase fields are written only when the caller sent them, so an
       api/v1 save - which knows nothing of them - leaves them as they were. */
    $columns += phaseFieldColumns($f);

    if ($existing) {
        $sets = implode(", ", array_map(function ($c) { return "`$c` = ?"; }, array_keys($columns)));
        $stmt = $pdo->prepare("UPDATE project_tasks SET $sets WHERE id = ?");
        $stmt->execute(array_merge(array_values($columns), [(int) $existing["id"]]));
        return (int) $existing["id"];
    }

    $columns["project_id"]          = (int) $project["id"];
    $columns["created_by_admin_id"] = (int) $actor["id"];

    $names  = implode(", ", array_map(function ($c) { return "`$c`"; }, array_keys($columns)));
    $marks  = implode(", ", array_fill(0, count($columns), "?"));
    $stmt = $pdo->prepare("INSERT INTO project_tasks ($names) VALUES ($marks)");
    $stmt->execute(array_values($columns));

    return (int) $pdo->lastInsertId();
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
|   teammates     who is on the phase; each ticks their own box done
|   assignments   who worked on it, what they did, and how far they got -
|                 the rows the admin dashboard lists as "My tasks"
|
| Only someone who may edit the task changes either list. Each person moves
| only their own box and their own status, with no Save needed.
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

/** Teammates and assignments of a phase, with names, for the pages. */
function taskTeamPayload(PDO $pdo, $taskId)
{
    $stmt = $pdo->prepare("
        SELECT m.admin_id AS id, a.name, a.role, m.is_done
        FROM project_task_teammates m
        LEFT JOIN admins a ON a.id = m.admin_id
        WHERE m.task_id = ?
        ORDER BY a.name ASC
    ");
    $stmt->execute([(int) $taskId]);
    $teammates = array_map(function ($r) {
        return ["id" => (int) $r["id"], "name" => (string) $r["name"],
                "role" => (string) $r["role"], "is_done" => (int) $r["is_done"]];
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

    return $added;
}

/**
 * Makes the phase's assignments exactly $rows: [{id?, admin_id, work_note,
 * status}]. A row with an id that belongs to this phase is updated, a row
 * without one is added, and an existing row left out is removed. Returns
 * the people new to the phase's assignments.
 */
function saveTaskAssignments(PDO $pdo, array $project, $taskId, array $rows)
{
    $stmt = $pdo->prepare("SELECT id, admin_id FROM project_task_assignments WHERE task_id = ?");
    $stmt->execute([(int) $taskId]);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(int) $r["id"]] = (int) $r["admin_id"];
    }

    $allowed = allowedTaskPeople($pdo, $project, array_column($rows, "admin_id"), array_values($existing));

    $keep  = [];
    $added = [];

    $update = $pdo->prepare("UPDATE project_task_assignments SET admin_id = ?, work_note = ?, status = ? WHERE id = ? AND task_id = ?");
    $insert = $pdo->prepare("INSERT INTO project_task_assignments (task_id, admin_id, work_note, status) VALUES (?, ?, ?, ?)");

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $adminId = (int) ($row["admin_id"] ?? 0);
        if (!in_array($adminId, $allowed, true)) {
            continue;
        }

        $note = trim((string) ($row["work_note"] ?? ""));
        if (mb_strlen($note) > 2000) {
            throw new PortalWriteError("What they did must be 2000 characters or fewer.");
        }
        $status = array_key_exists((string) ($row["status"] ?? ""), TASK_ASSIGNMENT_STATUSES) ? $row["status"] : "todo";
        $id = (int) ($row["id"] ?? 0);

        if ($id && isset($existing[$id])) {
            $update->execute([$adminId, $note ?: null, $status, $id, (int) $taskId]);
            $keep[] = $id;
            if ($existing[$id] !== $adminId) {
                $added[] = $adminId;
            }
        } else {
            $insert->execute([(int) $taskId, $adminId, $note ?: null, $status]);
            $keep[] = (int) $pdo->lastInsertId();
            if (!in_array($adminId, $existing, true)) {
                $added[] = $adminId;
            }
        }
    }

    $gone = array_values(array_diff(array_keys($existing), $keep));
    if ($gone) {
        $marks = implode(",", array_fill(0, count($gone), "?"));
        $pdo->prepare("DELETE FROM project_task_assignments WHERE task_id = ? AND id IN ($marks)")
            ->execute(array_merge([(int) $taskId], $gone));
    }

    return array_values(array_unique($added));
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

    return $status;
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
