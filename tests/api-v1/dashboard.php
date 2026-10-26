<?php

/*
| The admin dashboard - required by run.php after writes.php, sharing its
| scope. api/admin-dashboard.php lists the caller's own assignments
| (project_task_assignments) and api/project-tasks.php action=set_my_status
| moves the caller's own row. Nothing on the pages creates assignments yet,
| so they are seeded here.
|
| Sean (5) is a member of project 501 and assigned to client 10, so he sees
| both projects; Amy (3) manages 500 only.
*/

seed($pdo, "project_tasks", [
    ["id" => 650, "project_id" => 500, "title" => "Brand moodboard", "status" => "published",
     "scheduled_date" => day("+2"), "created_by_admin_id" => 3, "phase_label" => "Design"],
]);
seed($pdo, "project_task_assignments", [
    ["id" => 1, "task_id" => 650, "admin_id" => 5, "work_note" => "Collecting references", "status" => "todo"],
    ["id" => 2, "task_id" => 601, "admin_id" => 5, "status" => "done"],
    ["id" => 3, "task_id" => 650, "admin_id" => 3, "status" => "in_progress"],
]);

[, $d] = pageGet("api/admin-dashboard.php", $seo);
check("dashboard: my assignments, soonest first", array_map("intval", array_column($d["tasks"] ?? [], "task_id")) === [601, 650], json_encode($d));
check("dashboard: counts and projects", ($d["counts"] ?? null) === ["todo" => 1, "in_progress" => 0, "done" => 1]
    && ($d["projects"] ?? 0) === 2 && ($d["tasks"][1]["phase_label"] ?? null) === "Design", json_encode($d));
[, $d] = pageGet("api/admin-dashboard.php", $am2);
check("dashboard: someone with no assignments sees none", ($d["success"] ?? false) && ($d["tasks"] ?? null) === []
    && ($d["projects"] ?? 0) === 1, json_encode($d));
[$status] = pageGet("api/admin-dashboard.php", "tok-nobody");
check("dashboard: a bad token is a 401", $status === 401, "HTTP $status");

[, $r] = post("api/project-tasks.php", $seo, ["action" => "set_my_status", "task_id" => 650, "status" => "in_progress"], [], true);
check("dashboard: I move my own status", ($r["success"] ?? false)
    && one($pdo, "SELECT status FROM project_task_assignments WHERE id = 1")["status"] === "in_progress"
    && one($pdo, "SELECT status FROM project_task_assignments WHERE id = 3")["status"] === "in_progress", json_encode($r));
[$status, $r] = post("api/project-tasks.php", $sa, ["action" => "set_my_status", "task_id" => 650, "status" => "done"], [], true);
check("dashboard: not assigned, nothing to move", $status === 403, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/project-tasks.php", $seo, ["action" => "set_my_status", "task_id" => 650, "status" => "finished"], [], true);
check("dashboard: an unknown status is refused", $status === 400, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/project-tasks.php", $am2, ["action" => "set_my_status", "task_id" => 650, "status" => "done"], [], true);
check("dashboard: a project out of scope is a 404", $status === 404, "HTTP $status " . json_encode($r));

$r = json_decode((string) @file_get_contents("http://127.0.0.1:$port/api/project-tasks.php?id=650", false, stream_context_create([
    "http" => ["method" => "DELETE", "header" => "Authorization: Bearer $owner", "ignore_errors" => true, "timeout" => 10],
])), true);
check("dashboard: deleting a phase takes its assignments with it", ($r["success"] ?? false)
    && one($pdo, "SELECT COUNT(*) AS n FROM project_task_assignments WHERE task_id = 650")["n"] == 0, json_encode($r));
