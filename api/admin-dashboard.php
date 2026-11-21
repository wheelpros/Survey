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
| Whoever has a team also gets `team`, shown as a Team tasks tab beside My
| tasks: one row per phase and post, with everyone on it and their own status
| (teamDashboardItems() in task-writes.php). team.overview (the Director) sees
| everyone's, with what needs attention; an Account Manager or Super Admin who
| manages people sees the work those people are on (scope: "my_team").
|
| Read-only. Changing "My status" posts to the endpoint that owns the thing:
| project-tasks.php (set_my_status, set_done) or admin-content.php (set_done).
|
| The list is myDashboardItems() in task-writes.php; phases only from projects
| inside projectScopeSql(), the same rule the project pages use.
|
*/

require_once "db.php";
require_once "auth.php";
requirePage($pdo, "dashboard"); // "Open X" in Settings > Permissions
require_once "task-writes.php";
require_once "team-scope.php"; // teamPoolFor()

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

$admin = requireAdmin($pdo);

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, "Unsupported request method.", [], 405);
}

ensureProjectTables($pdo);

try {
    $mine = myDashboardItems($pdo, $admin);

    // Team tasks, beside My tasks: everyone's for team.overview (the
    // Director), the people they manage for an Account Manager or Super Admin
    // with a team. Everyone else gets no `team` key - one view, no tabs.
    $team = null;
    if (can($admin, "team.overview", $pdo)) {
        $team = teamDashboardItems($pdo, $admin);
    } else {
        $pool = array_keys(teamPoolFor($pdo, $admin)); // nobody, for someone who manages no one
        if ($pool) {
            $team = teamDashboardItems($pdo, $admin, $pool);
        }
    }
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
