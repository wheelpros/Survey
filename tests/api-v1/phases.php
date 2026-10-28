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
    "is_complete" => "1",
    "teammates"   => json_encode([2, 5, 4]),
    // A status sent with a row is ignored: each person sets their own.
    "assignments" => json_encode([
        ["admin_id" => 5, "work_note" => "Wrote the brief", "status" => "done"],
    ]),
], [], true);
$phase = one($pdo, "SELECT * FROM project_tasks WHERE title = 'Project brief'");
$phaseId = (int) ($phase["id"] ?? 0);

check("phases: the phase fields are saved", $phase && $phase["phase_label"] === "Planning" && $phase["role"] === "Project Manager"
    && $phase["priority"] === "high" && $phase["notes"] === "**Kickoff** on Monday", json_encode($r));
check("phases: only the client's team goes on it", phasePeople($pdo, "project_task_teammates", $phaseId) === [2, 5]
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [5]);
check("phases: nobody sets a status or completion from the form",
    one($pdo, "SELECT status FROM project_task_assignments WHERE task_id = ? AND admin_id = 5", [$phaseId])["status"] === "todo"
    && (int) $phase["is_complete"] === 0, json_encode($phase));

// Assignments come from the phase's teammates only.
[$status, $r] = post("api/project-tasks.php", $owner, [
    "id" => $phaseId, "title" => "Project brief", "status" => "published",
    "assignments" => json_encode([["admin_id" => 3, "work_note" => "Not a teammate"]]),
], [], true);
check("phases: someone not on the team can't be assigned", ($r["success"] ?? true) === false
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [5], "HTTP $status " . json_encode($r));
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
check("phases: everyone assigned is done, so the phase is complete", ($r["is_complete"] ?? null) === true
    && (int) one($pdo, "SELECT is_complete FROM project_tasks WHERE id = ?", [$phaseId])["is_complete"] === 1, json_encode($r));
// Sam is a teammate, and a Super Admin, but not assigned: no row to move.
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

// A person listed again keeps their row and the status they set; a new one
// starts at To do, which reopens the phase.
$keepId = (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$phaseId])["id"];
[, $r] = post("api/project-tasks.php", $owner, [
    "id" => $phaseId, "title" => "Project brief", "status" => "published",
    "teammates" => json_encode([2, 3, 5]),
    "assignments" => json_encode([
        ["admin_id" => 5, "work_note" => "Brief signed off"],
        ["admin_id" => 3, "work_note" => ""],
    ]),
], [], true);
$rows = $pdo->prepare("SELECT id, admin_id, work_note, status FROM project_task_assignments WHERE task_id = ? ORDER BY admin_id");
$rows->execute([$phaseId]);
$rows = $rows->fetchAll();
check("phases: assignments are kept by person", ($r["success"] ?? false) && count($rows) === 2
    && (int) $rows[0]["admin_id"] === 3 && $rows[0]["status"] === "todo"
    && (int) $rows[1]["id"] === $keepId && $rows[1]["work_note"] === "Brief signed off" && $rows[1]["status"] === "done", json_encode($r));
check("phases: a new assignee reopens the phase",
    (int) one($pdo, "SELECT is_complete FROM project_tasks WHERE id = ?", [$phaseId])["is_complete"] === 0);

// Taking Amy off the team takes her off the Assigned table, which completes it again.
[, $r] = post("api/project-tasks.php", $owner, [
    "id" => $phaseId, "title" => "Project brief", "status" => "published", "teammates" => json_encode([2, 5]),
], [], true);
check("phases: leaving the team leaves the Assigned table", ($r["success"] ?? false)
    && phasePeople($pdo, "project_task_assignments", $phaseId) === [5]
    && (int) one($pdo, "SELECT is_complete FROM project_tasks WHERE id = ?", [$phaseId])["is_complete"] === 1, json_encode($r));

// Delete is the Director's, an account manager's or the creator's.
[, $t] = pageGet("api/project-tasks.php?id=$phaseId", $owner);
[, $tSeo] = pageGet("api/project-tasks.php?id=$phaseId", $seo);
check("phases: can_delete follows who may delete", ($t["can_delete"] ?? null) === true && ($tSeo["can_delete"] ?? null) === false,
    json_encode([$t["can_delete"] ?? null, $tSeo["can_delete"] ?? null]));
$r = json_decode((string) @file_get_contents("http://127.0.0.1:$port/api/project-tasks.php?id=$phaseId", false, stream_context_create([
    "http" => ["method" => "DELETE", "header" => "Authorization: Bearer $seo", "ignore_errors" => true, "timeout" => 10],
])), true);
check("phases: a teammate can't delete it", ($r["success"] ?? true) === false
    && one($pdo, "SELECT id FROM project_tasks WHERE id = ?", [$phaseId]) !== false, json_encode($r));

/** The dashboard items of $kind about $id. */
function dashItems(array $d, $kind, $id)
{
    return array_values(array_filter($d["tasks"] ?? [], function ($t) use ($kind, $id) {
        return $t["kind"] === $kind && (int) $t["id"] === (int) $id;
    }));
}

/** The counts the items add up to. */
function dashTally(array $d)
{
    $counts = ["todo" => 0, "in_progress" => 0, "done" => 0];
    foreach ($d["tasks"] ?? [] as $t) {
        $counts[$t["status"]]++;
    }
    return $counts;
}

// Sean: assigned on the phase and on 601 (and a teammate on both), and on the post "Acme reel".
[, $d] = pageGet("api/admin-dashboard.php", $seo);
$mine = dashItems($d, "assignment", $phaseId);
check("dashboard: my phase, my status", count($mine) === 1 && $mine[0]["status"] === "done"
    && strpos($mine[0]["href"], "project-task-details.html?project=500&id=$phaseId") === 0, json_encode($d));
check("dashboard: a phase I am assigned to and a teammate on shows once", !dashItems($d, "teammate", $phaseId) && !dashItems($d, "teammate", 601)
    && count(dashItems($d, "assignment", 601)) === 1, json_encode($d));
$post = dashItems($d, "content", 302);
check("dashboard: a content post I am on", count($post) === 1 && $post[0]["status"] === "todo" && $post[0]["chip"] === "Acme Ltd"
    && $post[0]["href"] === "admin-content-form.html?id=302" && $post[0]["due_date"] === day("+3"), json_encode($d));
check("dashboard: a post I am not on isn't there", !dashItems($d, "content", 300) && !dashItems($d, "content", 303), json_encode($d));
check("dashboard: the counts are the tally of everything listed", dashTally($d) === $d["counts"]
    && ($d["counts"]["in_progress"] ?? 0) === 1 && ($d["projects"] ?? 0) === 2, json_encode($d));

// Sam: only a teammate on the phase (ticked), and on the post "Acme hello".
[, $d] = pageGet("api/admin-dashboard.php", $sa);
$team = dashItems($d, "teammate", $phaseId);
check("dashboard: a phase I am only a teammate on, with my box", count($team) === 1 && $team[0]["status"] === "done"
    && count(dashItems($d, "content", 303)) === 1 && dashTally($d) === $d["counts"] && ($d["counts"]["in_progress"] ?? 1) === 0, json_encode($d));

[, $d] = pageGet("api/admin-dashboard.php", $am2);
check("dashboard: someone on nothing sees none", ($d["success"] ?? false) && ($d["tasks"] ?? null) === []
    && ($d["projects"] ?? 0) === 1, json_encode($d));
[$status] = pageGet("api/admin-dashboard.php", "tok-nobody");
check("dashboard: a bad token is a 401", $status === 401, "HTTP $status");

// The Team overview: the Director sees every person's tasks; nobody else
// gets one.
/** The team items about phase $id. */
function teamItems(array $d, $kind, $id)
{
    return array_values(array_filter($d["team"]["tasks"] ?? [], function ($t) use ($kind, $id) {
        return $t["kind"] === $kind && (int) $t["id"] === (int) $id;
    }));
}
[, $d] = pageGet("api/admin-dashboard.php", $owner);
$sean601 = teamItems($d, "assignment", 601);
check("team: the Director sees everyone's phases", count($sean601) === 1 && $sean601[0]["person"]["name"] === "Sean"
    && count(teamItems($d, "assignment", $phaseId)) === 1, json_encode($d["team"] ?? null));
$tally = ["todo" => 0, "in_progress" => 0, "done" => 0, "overdue" => 0];
foreach ($d["team"]["tasks"] ?? [] as $t) {
    $tally[$t["status"]]++;
    $tally["overdue"] += $t["overdue"] ? 1 : 0;
}
check("team: the counts are the tally, with overdue", $tally === ($d["team"]["counts"] ?? null)
    && ($sean601[0]["overdue"] ?? null) === ($sean601[0]["status"] !== "done" && $sean601[0]["due_date"] < date("Y-m-d")), json_encode($d["team"]["counts"] ?? null));
check("team: what needs attention", isset($d["team"]["attention"]["unassigned_phases"], $d["team"]["attention"]["forms_to_review"]["count"],
    $d["team"]["attention"]["meetings_waiting"]["count"]), json_encode($d["team"]["attention"] ?? null));
// The Team overview is the Director's alone: an account manager sees My tasks.
foreach (["account manager" => $am, "super admin" => $sa, "admin" => $seo] as $who => $token) {
    [, $d] = pageGet("api/admin-dashboard.php", $token);
    check("team: a $who has no Team overview", ($d["success"] ?? false) && !array_key_exists("team", $d), json_encode(array_keys($d)));
}
[$status, $v1] = get("projects.php?tasks=1", $owner);
check("team v1: list_my_tasks carries the Team overview for the Director", $status === 200
    && count(array_filter($v1["data"]["team"]["items"] ?? [], function ($t) { return $t["id"] === 601 && $t["kind"] === "assignment"; })) === 1
    && isset($v1["data"]["team"]["counts"]["overdue"]), json_encode($v1["data"]["team"] ?? null));
foreach (["an account manager" => $am, "an Admin" => $seo] as $who => $token) {
    [$status, $v1] = get("projects.php?tasks=1", $token);
    check("team v1: not for $who", $status === 200 && !array_key_exists("team", $v1["data"] ?? []), json_encode(array_keys($v1["data"] ?? [])));
}

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
    "assignments" => [["admin_id" => 5, "work_note" => "Mockups"]],
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
refusedChange("v1 phases: assignments take no status", $owner, "update_task",
    ["id" => $v1Phase, "assignments" => [["admin_id" => 5, "status" => "done"]]], 422);
refusedChange("v1 phases: only teammates are assigned", $owner, "update_task",
    ["id" => $v1Phase, "assignments" => [["admin_id" => 3]]], 422);
refusedChange("v1 phases: completion is never set by hand", $owner, "update_task",
    ["id" => $v1Phase, "is_complete" => true], 422);

$rowId = (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$v1Phase])["id"];
change("v1 phases: change only the priority", $owner, "update_task", ["id" => $v1Phase, "priority" => "low"]);
check("v1 phases: the people are left alone", phasePeople($pdo, "project_task_teammates", $v1Phase) === [2, 5]
    && (int) one($pdo, "SELECT id FROM project_task_assignments WHERE task_id = ?", [$v1Phase])["id"] === $rowId
    && one($pdo, "SELECT priority FROM project_tasks WHERE id = ?", [$v1Phase])["priority"] === "low");

change("v1 phases: listing someone again keeps their row", $owner, "update_task", ["id" => $v1Phase,
    "team" => [2, 3, 5],
    "assignments" => [["admin_id" => 5, "work_note" => "Mockups signed off"], ["admin_id" => 3]]]);
$kept = one($pdo, "SELECT id, work_note, status FROM project_task_assignments WHERE task_id = ? AND admin_id = 5", [$v1Phase]);
check("v1 phases: same row, new values", (int) $kept["id"] === $rowId && $kept["work_note"] === "Mockups signed off"
    && phasePeople($pdo, "project_task_assignments", $v1Phase) === [3, 5]);

[$status, $got] = get("projects.php?id=500", $owner);
$found = array_values(array_filter($got["data"]["tasks"] ?? [], function ($t) use ($v1Phase) { return $t["id"] === $v1Phase; }));
check("v1 phases: get_project shows the people", $found && $found[0]["phase_label"] === "Design"
    && count($found[0]["teammates"]) === 3 && count($found[0]["assignments"]) === 2
    && $found[0]["done_count"] === "0 of 2" && $found[0]["can_delete"] === true && $found[0]["attachments"] === []
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
refusedChange("v1 phases: not assigned, no status to move", $sa, "set_my_phase_status", ["id" => $v1Phase, "status" => "done"], 403);
refusedChange("v1 phases: status is needed", $sa, "set_my_phase_status", ["id" => $v1Phase], 422);
refusedChange("v1 phases: a project out of scope", $am2, "set_my_phase_status", ["id" => $v1Phase, "status" => "done"], 404);

// done, the old teammate box, is read as status done; the last one done completes the phase.
change("v1 phases: done is status done", $seo, "set_my_phase_status", ["id" => $v1Phase, "done" => true]);
[, $last] = change("v1 phases: the last one done", $am, "set_my_phase_status", ["id" => $v1Phase, "status" => "done"]);
check("v1 phases: the phase completes by itself", ($last["data"]["result"]["is_complete"] ?? null) === true
    && (int) one($pdo, "SELECT is_complete FROM project_tasks WHERE id = ?", [$v1Phase])["is_complete"] === 1, json_encode($last));

refusedChange("v1 phases: a teammate can't delete it", $seo, "delete_task", ["id" => $v1Phase], 403);
change("v1 phases: the Director deletes it", $owner, "delete_task", ["id" => $v1Phase]);
check("v1 phases: deleted with its people", one($pdo, "SELECT id FROM project_tasks WHERE id = ?", [$v1Phase]) === false
    && phasePeople($pdo, "project_task_assignments", $v1Phase) === [] && phasePeople($pdo, "project_task_teammates", $v1Phase) === []);

// ── list_my_tasks and set_my_post_done (api/v1) ─────────────────────────

/** The v1 task items of $kind about $id. */
function v1TaskItems(array $d, $kind, $id)
{
    return array_values(array_filter($d["data"]["items"] ?? [], function ($t) use ($kind, $id) {
        return $t["kind"] === $kind && (int) $t["id"] === (int) $id;
    }));
}

[$status, $v1] = get("projects.php?tasks=1", $seo);
[, $page] = pageGet("api/admin-dashboard.php", $seo);
$v1Keys = array_map(function ($t) { return $t["kind"] . ":" . $t["id"]; }, $v1["data"]["items"] ?? []);
$pageKeys = array_map(function ($t) { return $t["kind"] . ":" . $t["id"]; }, $page["tasks"] ?? []);
check("my tasks v1: the dashboard's own list and counts", $status === 200 && $v1Keys === $pageKeys
    && ($v1["data"]["counts"] ?? null) === ($page["counts"] ?? null) && ($v1["data"]["projects"] ?? 0) === ($page["projects"] ?? -1), json_encode([$v1, $page]));
$post = v1TaskItems($v1, "content", 302);
check("my tasks v1: a post I am on, with its link", count($post) === 1 && $post[0]["belongs_to"] === "Acme Ltd"
    && $post[0]["link"] === "admin-content-form.html?id=302" && in_array($post[0]["status"], ["todo", "done"], true), json_encode($post));
check("my tasks v1: a phase I am assigned to", count(v1TaskItems($v1, "assignment", 601)) === 1, json_encode($v1));

// Posts are content:write, which an Admin (seo_admin) doesn't hold - the same
// rule as editing a post over v1. Sam (Super Admin) is on post 303.
$before = (int) one($pdo, "SELECT is_done FROM content_assignees WHERE content_id = 303 AND admin_id = 2")["is_done"];
[$prep] = change("my post v1: tick my box", $sa, "set_my_post_done", ["id" => 303, "done" => $before !== 1]);
check("my post v1: only my box moved", (int) one($pdo, "SELECT is_done FROM content_assignees WHERE content_id = 303 AND admin_id = 2")["is_done"] === ($before === 1 ? 0 : 1)
    && strpos($prep["data"]["summary"] ?? "", "Only your own box changes") !== false, json_encode($prep));
refusedChange("my post v1: nothing to change", $sa, "set_my_post_done", ["id" => 303, "done" => $before !== 1], 409);
refusedChange("my post v1: not a post I am on", $sa, "set_my_post_done", ["id" => 300, "done" => true], 403);
refusedChange("my post v1: done is required", $sa, "set_my_post_done", ["id" => 303], 422);
refusedChange("my post v1: an Admin has no content:write", $seo, "set_my_post_done", ["id" => 302, "done" => true], 403);

// The post's Assigned table: three steps, each teammate their own.
change("my post v1: set my status", $sa, "set_my_post_status", ["id" => 303, "status" => "in_progress"]);
$row = one($pdo, "SELECT status, is_done FROM content_assignees WHERE content_id = 303 AND admin_id = 2");
check("my post v1: in progress is not done", $row["status"] === "in_progress" && (int) $row["is_done"] === 0, json_encode($row));
refusedChange("my post v1: an unknown status", $sa, "set_my_post_status", ["id" => 303, "status" => "finished"], 422);
refusedChange("my post v1: not a post I am on, no status", $sa, "set_my_post_status", ["id" => 300, "status" => "done"], 403);

[, $r] = post("api/admin-content.php", $sa, ["action" => "set_my_status", "content_id" => 303, "status" => "done"], [], true);
check("posts: the page sets my own status", ($r["success"] ?? false)
    && one($pdo, "SELECT status FROM content_assignees WHERE content_id = 303 AND admin_id = 2")["status"] === "done", json_encode($r));
[$status, $r] = post("api/admin-content.php", $am2, ["action" => "set_my_status", "content_id" => 303, "status" => "done"], [], true);
check("posts: someone not on the post has no status", $status === 403, "HTTP $status " . json_encode($r));
