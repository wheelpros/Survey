<?php

/*
| Projects fixes from the 2026-11 audit (docs/audit-2026-11): one section per
| finding, each written red against the code before its fix. Required by
| run.php near the end, so its own projects (58xx) and phases (58xxx) never
| shift what the earlier files count.
|
|   W-02  a phase's people come from the project's scope, not the company name
|   R-04  the single-phase GET applies the draft rule (= W-13)
|   W-16  a project save tells only the people newly put on it
|   D-03  deleting a project removes its phases' attachments and galleries
|   D-04  a phase save is all or nothing
*/

/** DELETE on a browser endpoint: [status, body]. */
function pageDelete($path, $token)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["method" => "DELETE", "header" => "Authorization: Bearer $token", "ignore_errors" => true, "timeout" => 20],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
}

/** The ids in a candidates list, lowest first. */
function candidateIds(array $reply)
{
    $ids = array_map("intval", array_column($reply["candidates"] ?? [], "id"));
    sort($ids);
    return $ids;
}

/** The admin ids on a phase in $table, lowest first. */
function pfPeople(PDO $pdo, $table, $taskId)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM $table WHERE task_id = ? ORDER BY admin_id");
    $stmt->execute([(int) $taskId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Earlier files end sessions (role changes, resets): sign the cast back in.
foreach ([1 => $owner, 2 => $sa, 3 => $am, 4 => $am2, 5 => $seo] as $adminId => $token) {
    $pdo->prepare("UPDATE admins SET session_token = ? WHERE id = ?")->execute([$token, $adminId]);
}

require_once dirname(__DIR__, 2) . "/api/upload-helpers.php";
ensureAttachmentsTable($pdo);

seed($pdo, "projects", [
    // Gina (12) has no company and nobody is assigned to her.
    ["id" => 5801, "client_id" => 12, "title" => "Gina site", "start_date" => day("-1"), "end_date" => day("+9"),
     "status" => "active", "created_by_admin_id" => 1],
    ["id" => 5802, "client_id" => 12, "title" => "Gina blog", "start_date" => day("-1"), "end_date" => day("+9"),
     "status" => "active", "account_manager_admin_id" => 3, "created_by_admin_id" => 1],
    // Acme's, for the save and delete checks.
    ["id" => 5803, "client_id" => 10, "title" => "Acme ads", "start_date" => day("-1"), "end_date" => day("+9"),
     "status" => "active", "progress" => 10, "account_manager_admin_id" => 3, "created_by_admin_id" => 3],
    ["id" => 5804, "client_id" => 10, "title" => "Acme print", "start_date" => day("-1"), "end_date" => day("+9"),
     "status" => "active", "account_manager_admin_id" => 3, "created_by_admin_id" => 1],
]);

// ── W-02: the phase pool is the project's scope ─────────────────────────

[$status, $r] = pageGet("api/project-tasks.php?project_id=5801", $owner);
check("W-02: a client with no company has nobody in scope, so nobody in the pool",
    $status === 200 && candidateIds($r) === [], "HTTP $status " . json_encode(candidateIds($r ?? [])));

[$status, $r] = pageGet("api/project-tasks.php?project_id=5802", $owner);
check("W-02: the project's manager is in its pool", $status === 200 && candidateIds($r) === [3],
    "HTTP $status " . json_encode(candidateIds($r ?? [])));

[$status, $r] = pageGet("api/project-tasks.php?project_id=501", $owner);
// Others may be on Beta by now; Andy (the manager, assigned) and Sean (a member) must be.
check("W-02: a project member is in its pool, beside the client's team", $status === 200
    && array_values(array_intersect([4, 5], candidateIds($r))) === [4, 5],
    "HTTP $status " . json_encode(candidateIds($r ?? [])));

[$status, $r] = get("projects.php?id=5801&teammates=1", $owner);
check("W-02: list_phase_teammates gives the same pool", $status === 200 && ($r["data"]["items"] ?? null) === [],
    "HTTP $status " . json_encode($r));

[, $r] = post("api/project-tasks.php", $owner, [
    "project_id" => 5801, "title" => "Out of scope team", "status" => "draft", "teammates" => json_encode([5]),
], [], true);
$w02 = one($pdo, "SELECT id FROM project_tasks WHERE project_id = 5801 AND title = 'Out of scope team'");
check("W-02: the browser leaves someone outside the scope off the phase",
    $w02 && pfPeople($pdo, "project_task_teammates", $w02["id"]) === [], json_encode($r));

refusedChange("W-02: api/v1 refuses someone outside the scope", $owner, "post_project_update",
    ["project" => 5801, "title" => "AI out of scope", "team" => [5]], 422);

// ── R-04 / W-13: one phase by id follows the draft rule ─────────────────

seed($pdo, "project_tasks", [
    ["id" => 58010, "project_id" => 501, "title" => "Andy's new draft", "status" => "draft", "created_by_admin_id" => 4],
    ["id" => 58011, "project_id" => 501, "title" => "Sean's new draft", "status" => "draft", "created_by_admin_id" => 5],
]);

[$status] = pageGet("api/project-tasks.php?id=58010", $seo);
check("R-04: a member can't open a teammate's draft by id", $status === 404, "HTTP $status");
[$status] = pageGet("api/project-tasks.php?id=58011", $seo);
check("R-04: a member opens their own draft", $status === 200, "HTTP $status");
[$status] = pageGet("api/project-tasks.php?id=601", $seo);
check("R-04: a member opens a published phase", $status === 200, "HTTP $status");
[$status] = pageGet("api/project-tasks.php?id=58011", $am2);
check("R-04: the project's manager opens any draft", $status === 200, "HTTP $status");
[$status] = pageGet("api/project-tasks.php?id=58010", $owner);
check("R-04: someone who sees every draft opens it", $status === 200, "HTTP $status");

// ── W-16: only the people newly put on a project are told ───────────────

function saveAcmeAds($token, $managerId, array $members)
{
    return post("api/projects.php", $token, [
        "id" => 5803, "title" => "Acme ads", "client_id" => 10, "status" => "active", "progress" => 10,
        "start_date" => day("-1"), "end_date" => day("+9"),
        "account_manager_admin_id" => $managerId, "member_ids" => implode(",", $members),
    ], [], true);
}

$amyBefore = notes($pdo, "admin", 3, "project_assigned");
$seanBefore = notes($pdo, "admin", 5, "project_assigned");
$andyBefore = notes($pdo, "admin", 4, "project_assigned");

[$status, $r] = saveAcmeAds($owner, 3, [5]);
check("W-16: a project save", $status === 200 && ($r["success"] ?? false), "HTTP $status " . json_encode($r));
check("W-16: the new member is told, the manager who was already there is not",
    notes($pdo, "admin", 5, "project_assigned") === $seanBefore + 1 && notes($pdo, "admin", 3, "project_assigned") === $amyBefore,
    json_encode([notes($pdo, "admin", 5, "project_assigned") - $seanBefore, notes($pdo, "admin", 3, "project_assigned") - $amyBefore]));

saveAcmeAds($owner, 3, [5]);
check("W-16: saving again tells nobody",
    notes($pdo, "admin", 5, "project_assigned") === $seanBefore + 1 && notes($pdo, "admin", 3, "project_assigned") === $amyBefore);

saveAcmeAds($owner, 4, [5]);
check("W-16: a new manager is told", notes($pdo, "admin", 4, "project_assigned") === $andyBefore + 1
    && notes($pdo, "admin", 5, "project_assigned") === $seanBefore + 1);

saveAcmeAds($owner, 3, []);
check("W-16: back as manager counts as newly put on", notes($pdo, "admin", 3, "project_assigned") === $amyBefore + 1);

// ── D-04: a phase save is all or nothing ────────────────────────────────

[$status, $r] = post("api/project-tasks.php", $owner, [
    "project_id" => 5803, "title" => "Half phase", "status" => "draft",
    "teammates" => json_encode([]), "assignments" => json_encode([["admin_id" => 3]]),
], [], true);
check("D-04: a refused new phase leaves no row behind", ($r["success"] ?? true) === false
    && !one($pdo, "SELECT id FROM project_tasks WHERE title = 'Half phase'"), "HTTP $status " . json_encode($r));

seed($pdo, "project_tasks", [["id" => 58030, "project_id" => 5803, "title" => "Whole phase", "status" => "draft", "created_by_admin_id" => 1]]);
[$status, $r] = post("api/project-tasks.php", $owner, [
    "id" => 58030, "title" => "Renamed phase", "status" => "draft",
    "teammates" => json_encode([2]), "assignments" => json_encode([["admin_id" => 3]]),
], [], true);
check("D-04: a refused edit changes nothing on the phase", ($r["success"] ?? true) === false
    && one($pdo, "SELECT title FROM project_tasks WHERE id = 58030")["title"] === "Whole phase"
    && pfPeople($pdo, "project_task_teammates", 58030) === [], "HTTP $status " . json_encode($r));

[$status, $r] = post("api/project-tasks.php", $owner, [
    "id" => 58030, "title" => "Renamed phase", "status" => "draft",
    "teammates" => json_encode([2, 3]), "assignments" => json_encode([["admin_id" => 3, "work_note" => "Copy"]]),
], [], true);
check("D-04: a good edit saves the phase and its people", ($r["success"] ?? false)
    && one($pdo, "SELECT title FROM project_tasks WHERE id = 58030")["title"] === "Renamed phase"
    && pfPeople($pdo, "project_task_teammates", 58030) === [2, 3]
    && pfPeople($pdo, "project_task_assignments", 58030) === [3], "HTTP $status " . json_encode($r));

[$status, $r] = post("api/project-tasks.php", $am, ["action" => "set_my_status", "task_id" => 58030, "status" => "done"], [], true);
check("D-04: setting your own status still completes the phase", ($r["success"] ?? false) && ($r["is_complete"] ?? false) === true,
    "HTTP $status " . json_encode($r));

change("D-04: api/v1 still saves a phase with its people", $owner, "post_project_update",
    ["project" => 5803, "title" => "AI whole phase", "publish" => false, "team" => [2], "assignments" => [["admin_id" => 2]]]);
$aiPhase = one($pdo, "SELECT id FROM project_tasks WHERE title = 'AI whole phase'");
check("D-04: api/v1 phase and people saved together", $aiPhase
    && pfPeople($pdo, "project_task_assignments", $aiPhase["id"]) === [2]);

// ── D-03 / F-04: deleting a project removes its phases' files ───────────

$root = dirname(__DIR__, 2);
@mkdir("$root/uploads/attachments", 0775, true);
$d03Files = [
    "uploads/attachments/d03-staff.txt",
    "uploads/attachments/d03-client.txt",
    "uploads/attachments/d03-gallery.jpg",
    "uploads/attachments/d03-cover.jpg",
];
foreach ($d03Files as $file) {
    file_put_contents("$root/$file", "x");
}
seed($pdo, "project_tasks", [["id" => 58040, "project_id" => 5804, "title" => "Print proofs", "status" => "published",
    "created_by_admin_id" => 1, "image_path" => "uploads/attachments/d03-cover.jpg",
    "gallery_paths" => json_encode(["uploads/attachments/d03-gallery.jpg"])]]);
seed($pdo, "project_task_teammates", [["task_id" => 58040, "admin_id" => 3]]);
seed($pdo, "attachments", [
    ["owner_type" => "task", "owner_id" => 58040, "path" => $d03Files[0], "original_name" => "staff.txt", "ext" => "txt", "size" => 1],
    ["owner_type" => CLIENT_PHASE_FILES_OWNER, "owner_id" => 58040, "path" => $d03Files[1], "original_name" => "client.txt", "ext" => "txt", "size" => 1],
]);

[$status, $r] = pageDelete("api/projects.php?id=5804", $owner);
check("D-03: the project is deleted", $status === 200 && ($r["success"] ?? false)
    && !one($pdo, "SELECT id FROM projects WHERE id = 5804") && !one($pdo, "SELECT id FROM project_tasks WHERE id = 58040"),
    "HTTP $status " . json_encode($r));
check("D-03: its phases' attachment rows go with it",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM attachments WHERE owner_id = 58040 AND owner_type IN ('task', ?)", [CLIENT_PHASE_FILES_OWNER])["n"] === 0);
check("F-04: and their files, gallery and cover leave the disk", array_values(array_filter($d03Files, function ($f) use ($root) {
    return is_file("$root/$f");
})) === [], json_encode(array_values(array_filter($d03Files, function ($f) use ($root) {
    return is_file("$root/$f");
}))));
foreach ($d03Files as $file) {
    @unlink("$root/$file");
}
