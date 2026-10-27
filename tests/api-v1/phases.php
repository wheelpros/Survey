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

// ── The same over api/v1 (MCP) ──────────────────────────────────────────

$samBefore  = notes($pdo, "admin", 2, "project_assigned");
$seanBefore = notes($pdo, "admin", 5, "project_assigned");

[$prep, $done] = change("v1 phases: post a phase with its people", $owner, "post_project_update", [
    "project" => 500, "title" => "AI phase", "publish" => false,
    "phase" => "Design", "role" => "UI Designer", "priority" => "high", "notes" => "Use the new palette",
    "team" => [2, 5],
    "assignments" => [["admin_id" => 5, "work_note" => "Mockups", "status" => "in_progress"]],
]);
$v1Phase = (int) ($done["data"]["result"]["task_id"] ?? 0);
$row = one($pdo, "SELECT * FROM project_tasks WHERE id = ?", [$v1Phase]);
check("v1 phases: the summary names the team", strpos($prep["data"]["summary"] ?? "", "For the team:") !== false, json_encode($prep));
check("v1 phases: fields and people are saved", $row && $row["phase_label"] === "Design" && $row["priority"] === "high"
    && phasePeople($pdo, "project_task_teammates", $v1Phase) === [2, 5]
    && phasePeople($pdo, "project_task_assignments", $v1Phase) === [5], json_encode($done));
check("v1 phases: each newcomer is told once", notes($pdo, "admin", 2, "project_assigned") === $samBefore + 1
    && notes($pdo, "admin", 5, "project_assigned") === $seanBefore + 1);

refusedChange("v1 phases: someone off the client's team is refused", $owner, "post_project_update",
    ["project" => 500, "title" => "x", "team" => [4]], 422);
refusedChange("v1 phases: one row per person", $owner, "update_task",
    ["id" => $v1Phase, "assignments" => [["admin_id" => 5], ["admin_id" => 5]]], 422);

$rowId = (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$v1Phase])["id"];
change("v1 phases: change only the priority", $owner, "update_task", ["id" => $v1Phase, "priority" => "low"]);
check("v1 phases: the people are left alone", phasePeople($pdo, "project_task_teammates", $v1Phase) === [2, 5]
    && (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$v1Phase])["id"] === $rowId
    && one($pdo, "SELECT priority FROM project_tasks WHERE id = ?", [$v1Phase])["priority"] === "low");

change("v1 phases: listing someone again keeps their row", $owner, "update_task", ["id" => $v1Phase,
    "assignments" => [["admin_id" => 5, "work_note" => "Mockups signed off", "status" => "done"], ["admin_id" => 3]]]);
$kept = one($pdo, "SELECT id, work_note, status FROM project_task_assignments WHERE task_id = ? AND admin_id = 5", [$v1Phase]);
check("v1 phases: same row, new values", (int) $kept["id"] === $rowId && $kept["status"] === "done"
    && phasePeople($pdo, "project_task_assignments", $v1Phase) === [3, 5]);

[$status, $got] = get("projects.php?id=500", $owner);
$found = array_values(array_filter($got["data"]["tasks"] ?? [], function ($t) use ($v1Phase) { return $t["id"] === $v1Phase; }));
check("v1 phases: get_project shows the people", $found && $found[0]["phase_label"] === "Design"
    && count($found[0]["teammates"]) === 2 && count($found[0]["assignments"]) === 2
    && $found[0]["untrusted_content"]["notes"] === "Use the new palette", json_encode($found));

[$status, $got] = get("projects.php?id=500&teammates=1", $owner);
$pool = array_column($got["data"]["items"] ?? [], "id");
sort($pool);
check("v1 phases: list_phase_teammates is the client's team", $status === 200 && $pool === [2, 3, 5], json_encode($got));

[$status, $got] = get("projects.php?mine=1", $am);
check("v1 phases: list_my_phases is mine", $status === 200 && array_column(array_column($got["data"]["items"] ?? [], "phase"), "id") === [$v1Phase]
    && ($got["data"]["counts"]["todo"] ?? null) === 1, json_encode($got));

change("v1 phases: I move my own status", $am, "set_my_phase_status", ["id" => $v1Phase, "status" => "in_progress"]);
check("v1 phases: my row moved", one($pdo, "SELECT status FROM project_task_assignments WHERE task_id = ? AND admin_id = 3", [$v1Phase])["status"] === "in_progress");
refusedChange("v1 phases: not on the phase, no status to move", $sa, "set_my_phase_status", ["id" => $v1Phase, "status" => "done"], 403);
refusedChange("v1 phases: no box when not a teammate", $am, "set_my_phase_status", ["id" => $v1Phase, "done" => true], 403);
change("v1 phases: a teammate ticks their box", $sa, "set_my_phase_status", ["id" => $v1Phase, "done" => true]);
refusedChange("v1 phases: status or done is needed", $sa, "set_my_phase_status", ["id" => $v1Phase], 422);
refusedChange("v1 phases: a project out of scope", $am2, "set_my_phase_status", ["id" => $v1Phase, "status" => "done"], 404);
