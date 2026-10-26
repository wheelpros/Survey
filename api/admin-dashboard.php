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
| api/project-tasks.php.
|
| The query is myPhaseAssignments() in task-writes.php: only projects inside
| projectScopeSql(), the same rule the project pages use.
|
*/

require_once "db.php";
require_once "task-writes.php";

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
    $mine = myPhaseAssignments($pdo, $admin);
} catch (Throwable $e) {
    response(false, "Could not load your dashboard.", [], 500);
}

response(true, "Dashboard loaded.", [
    "tasks"    => $mine["tasks"],
    "counts"   => $mine["counts"],
    "projects" => $mine["projects"],
    "statuses" => TASK_ASSIGNMENT_STATUSES,
    "today"    => date("Y-m-d"),
]);
