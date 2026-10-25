<?php

/*
| Phases - required by run.php after writes.php, sharing its scope. The
| teammates, assignments and phase fields project-task-form.html saves, the
| two "my own row" actions, and the admin dashboard that lists them.
|
| Project 500 is Acme's (client 10), so its team - the pool a phase picks
| people from - is Sam (2), Amy (3) and Sean (5). Andy (4) is Beta's.
*/

/** The admin ids on a phase, lowest first, from $table. */
function phasePeople(PDO $pdo, $table, $taskId)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM $table WHERE task_id = ? ORDER BY admin_id");
    $stmt->execute([(int) $taskId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$samBefore  = notes($pdo, "admin", 2, "project_assigned");
$seanBefore = notes($pdo, "admin", 5, "project_assigned");

[, $r] = post("api/project-tasks.php", $owner, [
    "project_id"  => 500,
    "title"       => "Project brief",
    "status"      => "published",
    "phase_label" => "Planning",
    "role"        => "Project Manager",
    "priority"    => "high",
    "notes"       => "**Kickoff** on Monday",
    "teammates"   => json_encode([2, 5, 4]),
    "assignments" => json_encode([
        ["admin_id" => 5, "work_note" => "Wrote the brief", "status" => "in_progress"],
        ["admin_id" => 4, "work_note" => "Not on Acme's team", "status" => "done"],
    ]),
], [], true);
$phase = one($pdo, "SELECT * FROM project_tasks WHERE title = 'Project brief'");
$phaseId = (int) ($phase["id"] ?? 0);

check("phases: the phase fields are saved", $phase && $phase["phase_label"] === "Planning" && $phase["role"] === "Project Manager"
    && $phase["priority"] === "high" && $phase["notes"] === "**Kickoff** on Monday", json_encode($r));
check("phases: only the client's team goes on it", phasePeople($pdo, "project_task_teammates", $phaseId) === [2, 5]
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [5]);
check("phases: each newcomer is told once", notes($pdo, "admin", 2, "project_assigned") === $samBefore + 1
    && notes($pdo, "admin", 5, "project_assigned") === $seanBefore + 1);

[, $r] = post("api/project-tasks.php", $owner, ["id" => $phaseId, "title" => "Project brief", "status" => "published"], [], true);
$phase = one($pdo, "SELECT * FROM project_tasks WHERE id = ?", [$phaseId]);
check("phases: a save without the team leaves it alone", ($r["success"] ?? false)
    && phasePeople($pdo, "project_task_teammates", $phaseId) === [2, 5]
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [5]
    && $phase["phase_label"] === "Planning" && $phase["notes"] === "**Kickoff** on Monday", json_encode($r));

[, $t] = pageGet("api/project-tasks.php?id=$phaseId", $owner);
$pool = array_map("intval", array_column($t["candidates"] ?? [], "id"));
sort($pool);
check("phases: the phase comes back with its people and its pool", count($t["teammates"] ?? []) === 2
    && ($t["assignments"][0]["work_note"] ?? "") === "Wrote the brief" && $pool === [2, 3, 5], json_encode($t));

[, $r] = post("api/project-tasks.php", $seo, ["action" => "set_my_status", "task_id" => $phaseId, "status" => "done"], [], true);
check("phases: the assignee moves their own status", ($r["success"] ?? false)
    && one($pdo, "SELECT status FROM project_task_assignments WHERE task_id = ? AND admin_id = 5", [$phaseId])["status"] === "done", json_encode($r));
[$status, $r] = post("api/project-tasks.php", $sa, ["action" => "set_my_status", "task_id" => $phaseId, "status" => "todo"], [], true);
check("phases: nobody else moves it", $status === 403
    && one($pdo, "SELECT status FROM project_task_assignments WHERE task_id = ? AND admin_id = 5", [$phaseId])["status"] === "done",
    "HTTP $status " . json_encode($r));
[$status, $r] = post("api/project-tasks.php", $seo, ["action" => "set_my_status", "task_id" => $phaseId, "status" => "finished"], [], true);
check("phases: an unknown status is refused", $status === 400, "HTTP $status " . json_encode($r));

[, $r] = post("api/project-tasks.php", $sa, ["action" => "set_done", "task_id" => $phaseId, "done" => "1"], [], true);
check("phases: a teammate ticks their own box", ($r["success"] ?? false)
    && (int) one($pdo, "SELECT is_done FROM project_task_teammates WHERE task_id = ? AND admin_id = 2", [$phaseId])["is_done"] === 1, json_encode($r));
[$status, $r] = post("api/project-tasks.php", $am, ["action" => "set_done", "task_id" => $phaseId, "done" => "1"], [], true);
check("phases: someone not on it has no box", $status === 403, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/project-tasks.php", $am2, ["action" => "set_done", "task_id" => $phaseId, "done" => "1"], [], true);
check("phases: a project out of scope is a 404", $status === 404, "HTTP $status " . json_encode($r));

// A row kept by id is updated in place; one left out is removed.
$keepId = (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$phaseId])["id"];
[, $r] = post("api/project-tasks.php", $owner, [
    "id" => $phaseId, "title" => "Project brief", "status" => "published",
    "assignments" => json_encode([
        ["id" => $keepId, "admin_id" => 5, "work_note" => "Brief signed off", "status" => "done"],
        ["admin_id" => 3, "work_note" => "", "status" => "todo"],
    ]),
], [], true);
$rows = $pdo->prepare("SELECT id, admin_id, work_note FROM project_task_assignments WHERE task_id = ? ORDER BY admin_id");
$rows->execute([$phaseId]);
$rows = $rows->fetchAll();
check("phases: assignments are diffed by id", ($r["success"] ?? false) && count($rows) === 2
    && (int) $rows[0]["admin_id"] === 3 && (int) $rows[1]["id"] === $keepId && $rows[1]["work_note"] === "Brief signed off", json_encode($r));

[, $d] = pageGet("api/admin-dashboard.php", $seo);
$mine = array_values(array_filter($d["tasks"] ?? [], function ($t) use ($phaseId) { return (int) $t["task_id"] === $phaseId; }));
check("dashboard: my phase, my status, my counts", count($mine) === 1 && $mine[0]["status"] === "done"
    && ($d["counts"]["done"] ?? 0) === 1 && ($d["projects"] ?? 0) === 2, json_encode($d));
[, $d] = pageGet("api/admin-dashboard.php", $am2);
check("dashboard: someone with no assignments sees none", ($d["success"] ?? false) && ($d["tasks"] ?? null) === []
    && ($d["projects"] ?? 0) === 1, json_encode($d));
[$status] = pageGet("api/admin-dashboard.php", "tok-nobody");
check("dashboard: a bad token is a 401", $status === 401, "HTTP $status");

$r = json_decode((string) @file_get_contents("http://127.0.0.1:$port/api/project-tasks.php?id=$phaseId", false, stream_context_create([
    "http" => ["method" => "DELETE", "header" => "Authorization: Bearer $owner", "ignore_errors" => true, "timeout" => 10],
])), true);
check("phases: deleting a phase takes its people with it", ($r["success"] ?? false)
    && phasePeople($pdo, "project_task_teammates", $phaseId) === []
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [], json_encode($r));
