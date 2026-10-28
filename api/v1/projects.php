<?php

/*
| Projects - client engagements, their task updates and their team.
|
|   GET projects.php?client=&status=&cursor=&limit=   list_projects
|   GET projects.php?id=N                             get_project
|   GET projects.php?id=N&teammates=1                 list_phase_teammates
|   GET projects.php?mine=1                           list_my_phases
|   GET projects.php?tasks=1                          list_my_tasks
|
| A project's tasks are its phases. Each carries the people on it: teammates
| (each ticks their own box done) and assignments (who worked on it, what
| they did, todo / in_progress / done). Teammates for a project are its
| client's team (task-writes.php taskTeamCandidates()); they are for changing
| a phase, so they need projects:write as well.
|
| Scope projects:read. Same rule as projects.php projectScope(): the owner
| sees every project; anyone else those of the clients assigned to them, the
| ones they manage, and the ones they are a member of.
|
| Inside a project, the draft rule from projects.php: the owner, account
| managers and the project's own manager see every task; everyone else the
| non-draft ones plus their own drafts. `is_live` says whether the client can
| see a task right now (published, and its date has come).
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../task-writes.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "projects:read");

ensureUserProfileColumns($pdo);
ensureProjectTables($pdo);

$scope = v1ProjectScopeSql($p);

const PROJECT_SQL = "
    SELECT p.id, p.client_id, p.title, p.description, p.project_type, p.status, p.progress,
           p.start_date, p.end_date, p.account_manager_admin_id, p.created_at, p.updated_at,
           u.name AS user_name, u.company_name,
           m.name AS account_manager_name,
           (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS task_count
    FROM projects p
    LEFT JOIN users u ON u.id = p.client_id
    LEFT JOIN admins m ON m.id = p.account_manager_admin_id
";

/** Teammates and assignments for $taskIds, keyed by task id. */
function phasePeopleFor(PDO $pdo, array $taskIds)
{
    $out = ["teammates" => [], "assignments" => []];
    if (!$taskIds) {
        return $out;
    }
    $in = implode(",", array_fill(0, count($taskIds), "?"));

    $stmt = $pdo->prepare("
        SELECT m.task_id, m.admin_id, a.name, a.role, m.is_done
        FROM project_task_teammates m
        LEFT JOIN admins a ON a.id = m.admin_id
        WHERE m.task_id IN ($in)
        ORDER BY a.name ASC
    ");
    $stmt->execute($taskIds);
    foreach ($stmt->fetchAll() as $r) {
        $out["teammates"][(int) $r["task_id"]][] = [
            "id" => (int) $r["admin_id"],
            "name" => (string) $r["name"],
            "role" => (string) $r["role"],
            "is_done" => (int) $r["is_done"] === 1,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT s.id, s.task_id, s.admin_id, a.name, a.role, s.status, s.work_note
        FROM project_task_assignments s
        LEFT JOIN admins a ON a.id = s.admin_id
        WHERE s.task_id IN ($in)
        ORDER BY s.id ASC
    ");
    $stmt->execute($taskIds);
    foreach ($stmt->fetchAll() as $r) {
        $out["assignments"][(int) $r["task_id"]][] = [
            "id" => (int) $r["id"],
            "admin_id" => (int) $r["admin_id"],
            "name" => (string) $r["name"],
            "role" => (string) $r["role"],
            // todo | in_progress | done
            "status" => (string) $r["status"],
            "untrusted_content" => ["work_note" => (string) ($r["work_note"] ?? "")],
        ];
    }

    return $out;
}

function projectItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        "client" => v1ClientRef($r["client_id"], $r["user_name"], $r["company_name"]),
        "type" => (string) $r["project_type"],
        "type_label" => PROJECT_TYPES[$r["project_type"]] ?? (string) $r["project_type"],
        // planning | active | review | completed
        "status" => (string) $r["status"],
        "status_label" => PROJECT_STATUSES[$r["status"]] ?? (string) $r["status"],
        "progress" => (int) $r["progress"],
        "start_date" => (string) $r["start_date"],
        "end_date" => (string) $r["end_date"],
        "account_manager_name" => v1NullableString($r["account_manager_name"]),
        "task_count" => (int) $r["task_count"],
    ];
}

/*
| My tasks: the admin dashboard's whole "My tasks" list (myDashboardItems()) -
| phases I am assigned to, phases I am a teammate on, and content posts I am
| on, each with my own status. Posts only for a connection that may read
| content; the counts are over what is returned.
*/
if (!empty($_GET["tasks"])) {
    $mine = myDashboardItems($pdo, $p);
    $withPosts = hasScope($p, "content:read");

    $items = array_values(array_filter($mine["tasks"], function ($t) use ($withPosts) {
        return $t["kind"] !== "content" || $withPosts;
    }));

    $counts = array_fill_keys(array_keys(TASK_ASSIGNMENT_STATUSES), 0);
    foreach ($items as $t) {
        if (isset($counts[$t["status"]])) {
            $counts[$t["status"]]++;
        }
    }

    v1Reply(200, [
        "items" => array_map(function ($t) {
            return [
                // assignment | teammate | content
                "kind" => $t["kind"],
                "id" => (int) $t["id"],
                "title" => (string) $t["title"],
                "belongs_to" => (string) $t["chip"],
                "due_date" => v1NullableString($t["due_date"]),
                "label" => v1NullableString($t["label"]),
                // todo | in_progress | done; a teammate box or a post is todo or done
                "status" => (string) $t["status"],
                "link" => (string) $t["href"],
            ];
        }, $items),
        "counts" => $counts,
        "projects" => $mine["projects"],
    ]);
}

/*
| My phases: the assignments the admin dashboard lists, and the same query
| (myPhaseAssignments()) - only projects this connection can see.
*/
if (!empty($_GET["mine"])) {
    $mine = myPhaseAssignments($pdo, $p);
    v1Reply(200, [
        "items" => array_map(function ($r) {
            return [
                "assignment_id" => (int) $r["assignment_id"],
                "phase" => ["id" => (int) $r["task_id"], "title" => (string) $r["title"]],
                "project" => ["id" => (int) $r["project_id"], "title" => (string) $r["project_title"]],
                "phase_label" => v1NullableString($r["phase_label"]),
                "due_date" => v1NullableString($r["scheduled_date"]),
                // todo | in_progress | done
                "status" => (string) $r["status"],
                "untrusted_content" => ["work_note" => (string) ($r["work_note"] ?? "")],
            ];
        }, $mine["tasks"]),
        "counts" => $mine["counts"],
        "projects" => $mine["projects"],
    ]);
}

$id = v1IntParam("id");

if ($id !== null && !empty($_GET["teammates"])) {
    v1RequireScope($p, "projects:write");
    $project = loadScopedProject($pdo, $p, $id);
    if (!$project) {
        v1Error(404, "not_found", "No project with that id among the projects you can see.");
    }
    v1Reply(200, [
        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
        "client" => v1NullableString($project["company_name"] ?? null),
        "items" => array_map(function ($c) use ($p) {
            return [
                "id" => $c["id"],
                "name" => $c["name"],
                "role" => $c["role"],
                "is_you" => $c["id"] === (int) $p["id"],
            ];
        }, taskTeamCandidates($pdo, $project)),
    ]);
}

if ($id !== null) {
    [$where, $params] = v1Where([["p.id = ?", [$id]], $scope]);
    $stmt = $pdo->prepare(PROJECT_SQL . $where . " LIMIT 1");
    $stmt->execute($params);
    $project = $stmt->fetch();

    if (!$project) {
        v1Error(404, "not_found", "No project with that id among the projects you can see.");
    }

    $seesEveryDraft = in_array($p["role"], ["owner", "account_manager"], true)
        || (int) $project["account_manager_admin_id"] === (int) $p["id"];

    $taskSql = "
        SELECT t.id, t.title, t.description, t.link, t.status, t.scheduled_date, t.scheduled_time,
               t.is_complete, t.phase_label, t.role, t.priority, t.notes, t.created_at,
               a.name AS created_by_name,
               " . TASK_IS_LIVE_SQL . " AS is_live
        FROM project_tasks t
        LEFT JOIN admins a ON a.id = t.created_by_admin_id
        WHERE t.project_id = ?
    ";
    $taskParams = [$id];
    if (!$seesEveryDraft) {
        $taskSql .= " AND (t.status <> 'draft' OR t.created_by_admin_id = ?)";
        $taskParams[] = $p["id"];
    }
    $taskSql .= " ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id DESC";

    $stmt = $pdo->prepare($taskSql);
    $stmt->execute($taskParams);
    $rows = $stmt->fetchAll();

    // The people on every phase, one query per list rather than one per phase.
    $people = phasePeopleFor($pdo, array_map("intval", array_column($rows, "id")));

    $tasks = array_map(function ($r) use ($people) {
        $taskId = (int) $r["id"];
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            // draft | published
            "status" => (string) $r["status"],
            "is_live" => (int) $r["is_live"] === 1,
            "is_complete" => (int) $r["is_complete"] === 1,
            "scheduled_date" => v1NullableString($r["scheduled_date"]),
            "scheduled_time" => $r["scheduled_time"] === null ? null : substr((string) $r["scheduled_time"], 0, 5),
            "link" => v1NullableString($r["link"]),
            "created_by_name" => v1NullableString($r["created_by_name"]),
            "created_at" => (string) $r["created_at"],
            "phase_label" => v1NullableString($r["phase_label"]),
            "role" => v1NullableString($r["role"]),
            // low | medium | high
            "priority" => (string) $r["priority"],
            "teammates" => $people["teammates"][$taskId] ?? [],
            "assignments" => $people["assignments"][$taskId] ?? [],
            "untrusted_content" => [
                "description" => v1PlainText($r["description"] ?? "", 2000),
                "notes" => (string) ($r["notes"] ?? ""),
            ],
        ];
    }, $rows);

    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.email, a.role
        FROM project_members pm
        JOIN admins a ON a.id = pm.admin_id
        WHERE pm.project_id = ?
        ORDER BY a.name ASC
    ");
    $stmt->execute([$id]);

    $data = projectItem($project);
    $data["created_at"] = (string) $project["created_at"];
    $data["updated_at"] = (string) $project["updated_at"];
    $data["members"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "name" => (string) $r["name"],
            "email" => (string) $r["email"],
            "role" => (string) $r["role"],
        ];
    }, $stmt->fetchAll());
    $data["tasks"] = $tasks;
    $data["untrusted_content"] = ["description" => v1PlainText($project["description"] ?? "", 4000)];

    v1Reply(200, $data);
}

$limit = v1Limit();
$after = v1IdCursor();
$parts = [$scope];

$status = v1EnumParam("status", array_keys(PROJECT_STATUSES));
if ($status !== null) {
    $parts[] = ["p.status = ?", [$status]];
}
$client = v1IntParam("client");
if ($client !== null) {
    $parts[] = ["p.client_id = ?", [$client]];
}
if ($after !== null) {
    $parts[] = ["p.id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare(PROJECT_SQL . $where . " ORDER BY p.id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, "projectItem"));
