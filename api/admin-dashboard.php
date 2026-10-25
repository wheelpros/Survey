<?php

/*
|--------------------------------------------------------------------------
| Admin dashboard: the phases I am working on
|--------------------------------------------------------------------------
|
| Backs admin-dashboard.html. Lists the signed-in admin's own assignments
| (project_task_assignments) with the phase and project each belongs to, the
| counts the donut and stat cards show, and how many projects they can see.
|
| Read-only: changing "My status" posts action=set_my_status to
| api/project-tasks.php, the one place an assignment's status is written.
|
| Only projects inside projectScopeSql() are listed, the same rule the
| project pages use - an assignment on a project the admin cannot open is
| not something to link to.
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

function response($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);

    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Admin token - the same check as api/project-tasks.php
|--------------------------------------------------------------------------
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization = $headers["Authorization"] ?? $headers["authorization"] ?? "";

if (!$authorization && isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authorization = $_SERVER["HTTP_AUTHORIZATION"];
}

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches) || !trim($matches[1])) {
    response(false, "Unauthorized.", [], 401);
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([trim($matches[1])]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        response(false, "Invalid or expired admin session.", [], 401);
    }
} catch (Throwable $e) {
    response(false, "Authentication database error.", [], 500);
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, "Unsupported request method.", [], 405);
}

ensureProjectTables($pdo);

try {
    [$scopeSql, $scopeParams] = projectScopeSql($admin);
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
    $stmt->execute(array_merge([(int) $admin["id"]], $scopeParams));
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = array_fill_keys(array_keys(TASK_ASSIGNMENT_STATUSES), 0);
    foreach ($tasks as $task) {
        if (isset($counts[$task["status"]])) {
            $counts[$task["status"]]++;
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE 1 = 1" . $scope);
    $stmt->execute($scopeParams);
    $projects = (int) $stmt->fetchColumn();

} catch (Throwable $e) {
    response(false, "Could not load your dashboard.", [], 500);
}

response(true, "Dashboard loaded.", [
    "tasks"    => $tasks,
    "counts"   => $counts,
    "projects" => $projects,
    "statuses" => TASK_ASSIGNMENT_STATUSES,
    "today"    => date("Y-m-d"),
]);
