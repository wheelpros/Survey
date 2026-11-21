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

// ── W-08: a project with published work stays with its client ───────────

function moveProject($token, $id, $title, $clientId)
{
    return post("api/projects.php", $token, [
        "id" => $id, "title" => $title, "client_id" => $clientId, "status" => "active",
        "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 0, "member_ids" => "",
    ], [], true);
}

seed($pdo, "projects", [
    ["id" => 5805, "client_id" => 10, "title" => "Acme drafts", "start_date" => day("-1"), "end_date" => day("+9"), "created_by_admin_id" => 1],
    ["id" => 5806, "client_id" => 10, "title" => "Acme live", "start_date" => day("-1"), "end_date" => day("+9"), "created_by_admin_id" => 1],
    ["id" => 5807, "client_id" => 10, "title" => "Acme files", "start_date" => day("-1"), "end_date" => day("+9"), "created_by_admin_id" => 1],
]);
seed($pdo, "project_tasks", [
    ["id" => 58050, "project_id" => 5805, "title" => "Only a draft", "status" => "draft", "created_by_admin_id" => 1],
    ["id" => 58060, "project_id" => 5806, "title" => "Published", "status" => "published", "scheduled_date" => day("+5"), "created_by_admin_id" => 1],
    ["id" => 58070, "project_id" => 5807, "title" => "Draft with the client's file", "status" => "draft", "created_by_admin_id" => 1],
]);
seed($pdo, "attachments", [
    ["owner_type" => CLIENT_PHASE_FILES_OWNER, "owner_id" => 58070, "path" => "uploads/attachments/w08-none.txt",
     "original_name" => "brief.txt", "ext" => "txt", "size" => 1],
]);

[$status, $r] = moveProject($owner, 5806, "Acme live", 11);
check("W-08: a project with a published phase can't move to another client", $status === 409 && ($r["success"] ?? true) === false
    && (int) one($pdo, "SELECT client_id FROM projects WHERE id = 5806")["client_id"] === 10, "HTTP $status " . json_encode($r));

[$status, $r] = moveProject($owner, 5807, "Acme files", 11);
check("W-08: nor one holding a file the client uploaded", $status === 409
    && (int) one($pdo, "SELECT client_id FROM projects WHERE id = 5807")["client_id"] === 10, "HTTP $status " . json_encode($r));

[$status, $r] = moveProject($owner, 5806, "Acme live, renamed", 10);
check("W-08: the same project still saves for its own client", $status === 200
    && one($pdo, "SELECT title FROM projects WHERE id = 5806")["title"] === "Acme live, renamed", "HTTP $status " . json_encode($r));

[$status, $r] = moveProject($owner, 5805, "Acme drafts", 11);
check("W-08: a project with drafts only can still move", $status === 200
    && (int) one($pdo, "SELECT client_id FROM projects WHERE id = 5805")["client_id"] === 11, "HTTP $status " . json_encode($r));

[$status, $r] = pageGet("api/projects.php?id=5806", $owner);
check("W-08: the form is told the client is fixed", $status === 200 && ($r["project"]["client_locked"] ?? null) === true
    && ($r2 = pageGet("api/projects.php?id=5805", $owner)[1]) && ($r2["project"]["client_locked"] ?? null) === false,
    "HTTP $status " . json_encode($r["project"]["client_locked"] ?? "missing"));

// ── W-09: managing a project counts only while you hold the permission ──

seed($pdo, "admins", [["id" => 5890, "name" => "Mona", "email" => "mona@w.test", "role" => "account_manager", "session_token" => "tok-mona"]]);
seed($pdo, "projects", [["id" => 5808, "client_id" => 11, "title" => "Beta video", "start_date" => day("-1"), "end_date" => day("+9"),
    "status" => "active", "account_manager_admin_id" => 5890, "created_by_admin_id" => 4]]);
seed($pdo, "project_tasks", [["id" => 58080, "project_id" => 5808, "title" => "Andy's storyboard", "status" => "draft", "created_by_admin_id" => 4]]);
$mona = "tok-mona";

/** What Mona reaches on 5808: [browser project, browser phases, v1 project, Andy's draft, in the pool, offered as manager]. */
function monaReach($mona, $owner)
{
    [$a] = pageGet("api/projects.php?id=5808", $mona);
    [$b] = pageGet("api/project-tasks.php?project_id=5808", $mona);
    [$c] = get("projects.php?id=5808", $mona);
    [$d] = pageGet("api/project-tasks.php?id=58080", $mona);
    [, $pool] = pageGet("api/project-tasks.php?project_id=5808", $owner);
    [, $form] = pageGet("api/projects.php?id=5808", $owner);
    return [$a, $b, $c, $d, in_array(5890, candidateIds($pool ?? []), true),
        in_array(5890, array_map("intval", array_column($form["managers"] ?? [], "id")), true)];
}

check("W-09: a manager who holds the permission reaches their project", monaReach($mona, $owner) === [200, 200, 200, 200, true, true],
    json_encode(monaReach($mona, $owner)));

storeAdminPermission($pdo, 5890, "projects.be_manager", false, 1);
check("W-09: with the permission switched off, the manager link counts for nothing", monaReach($mona, $owner) === [404, 404, 404, 404, false, false],
    json_encode(monaReach($mona, $owner)));
storeAdminPermission($pdo, 5890, "projects.be_manager", null, 1);

$pdo->exec("UPDATE admins SET role = 'seo_admin' WHERE id = 5890");
forgetPermissions();
check("W-09: a demoted manager loses the project", monaReach($mona, $owner) === [404, 404, 404, 404, false, false],
    json_encode(monaReach($mona, $owner)));

[$status, $r] = post("api/projects.php", $owner, [
    "id" => 5808, "title" => "Beta video", "client_id" => 11, "status" => "active",
    "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 5890, "member_ids" => "",
], [], true);
check("W-09: someone without the permission can't be made a project's manager", $status === 400
    && ($r["success"] ?? true) === false, "HTTP $status " . json_encode($r));

// ── W-09 on the client details page: drafts follow the same rule ────────

seed($pdo, "projects", [["id" => 5809, "client_id" => 11, "title" => "Beta podcast", "start_date" => day("-1"), "end_date" => day("+9"),
    "status" => "active", "account_manager_admin_id" => 4, "created_by_admin_id" => 1]]);
seed($pdo, "project_tasks", [["id" => 58090, "project_id" => 5809, "title" => "Sean's episode draft", "status" => "draft",
    "scheduled_date" => day("+2"), "created_by_admin_id" => 5]]);

/** Whether Andy's client details page for Beta lists the draft. */
function andySeesBetaDraft($am2)
{
    [, $r] = pageGet("api/admin-user-details.php?id=11", $am2);
    return in_array(58090, array_map("intval", array_column($r["phases"] ?? [], "id")), true);
}

storeAdminPermission($pdo, 4, "tasks.see_drafts", false, 1);
check("W-09: the client page shows the manager their project's drafts", andySeesBetaDraft($am2) === true);
storeAdminPermission($pdo, 4, "projects.be_manager", false, 1);
check("W-09: not once they may no longer manage projects", andySeesBetaDraft($am2) === false);
storeAdminPermission($pdo, 4, "projects.be_manager", null, 1);
storeAdminPermission($pdo, 4, "tasks.see_drafts", null, 1);
