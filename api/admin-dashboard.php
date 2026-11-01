<?php

/*
|--------------------------------------------------------------------------
| Admin dashboard: what I am working on
|--------------------------------------------------------------------------
|
| Backs admin-dashboard.html. Lists everything that is the signed-in admin's:
| the phases they are assigned to, the phases they are a teammate on, and the
| content posts they are on - each with the project or client it belongs to,
| its due date and their own status - plus the counts the donut and stat cards
| show and how many projects they can see.
|
| The Director also gets `team`: the Team overview - their whole dashboard -
| every person's tasks, with what needs attention (teamDashboardItems() in
| task-writes.php). Everyone else, account managers included, sees My tasks.
|
| Read-only. Changing "My status" posts to the endpoint that owns the thing:
| project-tasks.php (set_my_status, set_done) or admin-content.php (set_done).
|
| The list is myDashboardItems() in task-writes.php; phases only from projects
| inside projectScopeSql(), the same rule the project pages use.
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
    $mine = myDashboardItems($pdo, $admin);

    // The Director also gets the Team overview - their whole dashboard: every
    // person's tasks they can see. Other roles get no `team` key.
    $team = can($admin, "team.overview", $pdo) ? teamDashboardItems($pdo, $admin) : null;
} catch (Throwable $e) {
    response(false, "Could not load your dashboard.", [], 500);
}

$out = [
    "tasks"    => $mine["tasks"],
    "counts"   => $mine["counts"],
    "projects" => $mine["projects"],
    "statuses" => TASK_ASSIGNMENT_STATUSES,
    "today"    => date("Y-m-d"),
];

if ($team !== null) {
    $out["team"] = $team;
}

response(true, "Dashboard loaded.", $out);
