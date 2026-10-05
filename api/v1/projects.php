<?php

/*
| Projects - client engagements, their task updates and their team.
|
|   GET projects.php?client=&status=&cursor=&limit=   list_projects
|   GET projects.php?id=N                             get_project
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

$id = v1IntParam("id");

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
               t.is_complete, t.created_at, a.name AS created_by_name,
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
    $tasks = array_map(function ($r) {
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
            "untrusted_content" => ["description" => v1PlainText($r["description"] ?? "", 2000)],
        ];
    }, $stmt->fetchAll());

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
