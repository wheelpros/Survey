<?php

/*
| The permission resolver, data scope and AI scope ceiling, checked in-process
| against the suite's database - required by run.php, sharing its scope. The
| HTTP side of the same rules is rbac-security.php, which reuses these people.
|
|   1. Matrix: every role x every key, with no rows, with a role row, with an
|      individual Allow or Deny on top, and back again.
|   2. Director-only keys: never from a role row; the Director ignores rows.
|   3. Who an admin action may target, and who may save which change.
|   4. Data scope: own, assigned, "department" (grants nothing) and all, per
|      resource, and records outside it by id.
|   5. AI: every scope is mapped, and no connection holds a scope its person
|      can't use.
|
|   9601 Ada   seo_admin         department 1; client 9701; wrote form 9711,
|                                post 9731; assigned to post 9733; member of
|                                project 9743
|   9602 Ben   seo_admin         department 1 (same as Ada); client 9702;
|                                wrote form 9712, posts 9732 and 9733;
|                                manages project 9742
|   9603 Cy    account_manager
|   9604 Viv   super_admin       Allow on every *.view_all-style key
|   9701 Acme Two  9702 Beta Two   clients
*/

$rbAdmins = [
    9601 => ["name" => "Ada", "role" => "seo_admin", "tok" => "tok-rb-ada"],
    9602 => ["name" => "Ben", "role" => "seo_admin", "tok" => "tok-rb-ben"],
    9603 => ["name" => "Cy", "role" => "account_manager", "tok" => "tok-rb-cy"],
    9604 => ["name" => "Viv", "role" => "super_admin", "tok" => "tok-rb-viv"],
];
foreach ($rbAdmins as $id => $a) {
    seed($pdo, "admins", [["id" => $id, "name" => $a["name"], "email" => strtolower($a["name"]) . "@rb.test",
        "role" => $a["role"], "session_token" => $a["tok"], "department_id" => in_array($id, [9601, 9602], true) ? 1 : null]]);
}
seed($pdo, "admin_permission_overrides", array_map(function ($perm) {
    return ["admin_id" => 9604, "permission" => $perm, "allowed" => 1];
}, ["clients.view_all", "forms.responses", "forms.review", "content.view_all", "projects.view_all"]));

seed($pdo, "users", [
    ["id" => 9701, "name" => "Acme Two", "email" => "a2@rb.test", "approved" => 1, "company_name" => "Acme Two", "session_token" => "tok-rb-client"],
    ["id" => 9702, "name" => "Beta Two", "email" => "b2@rb.test", "approved" => 1, "company_name" => "Beta Two"],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 9601, "user_id" => 9701], ["admin_id" => 9602, "user_id" => 9702]]);
seed($pdo, "surveys", [
    ["id" => 9711, "title" => "Ada form", "assigned_user_id" => 9701, "status" => "completed", "created_by_admin_id" => 9601],
    ["id" => 9712, "title" => "Ben form", "assigned_user_id" => 9702, "status" => "completed", "created_by_admin_id" => 9602],
]);
seed($pdo, "survey_responses", [
    ["id" => 9721, "user_id" => 9701, "survey_id" => 9711, "survey_title" => "Ada form"],
    ["id" => 9722, "user_id" => 9702, "survey_id" => 9712, "survey_title" => "Ben form"],
]);
seed($pdo, "content", [
    ["id" => 9731, "title" => "Ada post", "client" => "Acme Two", "content_type" => "photos", "type_label" => "Photos", "status" => "draft", "created_by" => 9601],
    ["id" => 9732, "title" => "Ben post", "client" => "Beta Two", "content_type" => "photos", "type_label" => "Photos", "status" => "draft", "created_by" => 9602],
    ["id" => 9733, "title" => "Ben post, Ada on it", "client" => "Beta Two", "content_type" => "photos", "type_label" => "Photos", "status" => "draft", "created_by" => 9602],
]);
seed($pdo, "content_assignees", [["content_id" => 9733, "admin_id" => 9601]]);
seed($pdo, "projects", [
    ["id" => 9741, "client_id" => 9701, "title" => "Acme Two site", "start_date" => day("-5"), "end_date" => day("+30"), "status" => "active", "created_by_admin_id" => 9603],
    ["id" => 9742, "client_id" => 9702, "title" => "Beta Two site", "start_date" => day("-5"), "end_date" => day("+30"), "status" => "active", "account_manager_admin_id" => 9602, "created_by_admin_id" => 9602],
    ["id" => 9743, "client_id" => 9702, "title" => "Beta Two ads", "start_date" => day("-5"), "end_date" => day("+30"), "status" => "active", "created_by_admin_id" => 9603],
]);
seed($pdo, "project_members", [["project_id" => 9743, "admin_id" => 9601]]);

function rbWho($id)
{
    global $rbAdmins;
    return ["kind" => "admin", "id" => $id, "role" => $rbAdmins[$id]["role"]];
}

function rbRows(PDO $pdo, $sql, array $params = [])
{
    $pdo->prepare($sql)->execute($params);
    forgetPermissions();
}

$ada = rbWho(9601); $ben = rbWho(9602); $cy = rbWho(9603); $viv = rbWho(9604);
$director = ["kind" => "admin", "id" => 1, "role" => "owner"];

// ── 1. Matrix ───────────────────────────────────────────────────────────
//
// Role rows apply to everyone in the role, so the stored ones are set aside
// for this section and put back after it.

$savedRoleRows = $pdo->query("SELECT role, permission, allowed, updated_by FROM role_permissions")->fetchAll();
rbRows($pdo, "DELETE FROM role_permissions");

// One fresh admin per role, with no rows of their own.
$matrixPeople = ["seo_admin" => 9611, "super_admin" => 9612, "account_manager" => 9613];
foreach ($matrixPeople as $role => $id) {
    seed($pdo, "admins", [["id" => $id, "name" => "Matrix $role", "email" => "m$id@rb.test", "role" => $role]]);
}
$grantable = array_values(array_filter(array_keys(PERMISSIONS), function ($k) {
    return !isOwnerOnlyPermission($k);
}));

/** The keys whose decision for $who isn't [$allowed($key), $source]. */
function matrixMismatches($who, array $keys, callable $allowed, $source)
{
    global $pdo;
    $bad = [];
    foreach ($keys as $key) {
        $want = ["allowed" => $allowed($key), "source" => $source];
        $got = permissionDecision($who, $key, $pdo);
        if ($got !== $want) {
            $bad[] = "$key " . json_encode($got);
        }
    }
    return $bad;
}

foreach ($matrixPeople as $role => $id) {
    $who = ["kind" => "admin", "id" => $id, "role" => $role];
    $default = function ($k) use ($role) {
        return permissionDefault($role, $k) && !isOwnerOnlyPermission($k);
    };

    $bad = matrixMismatches($who, array_keys(PERMISSIONS), $default, "default");
    check("matrix: $role with no rows holds exactly the shipped defaults", $bad === [], implode("; ", $bad));

    // A role row the opposite of every default.
    foreach ($grantable as $k) {
        $pdo->prepare("INSERT INTO role_permissions (role, permission, allowed) VALUES (?, ?, ?)")
            ->execute([$role, $k, permissionDefault($role, $k) ? 0 : 1]);
    }
    forgetPermissions();
    $flipped = function ($k) use ($role) {
        return !permissionDefault($role, $k);
    };
    $bad = matrixMismatches($who, $grantable, $flipped, "role");
    check("matrix: $role follows a stored role row on every key, both ways", $bad === [], implode("; ", $bad));

    // Overrides back to the default: an Allow over a role Deny and a Deny
    // over a role Allow, depending on the key.
    foreach ($grantable as $k) {
        $pdo->prepare("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (?, ?, ?)")
            ->execute([$id, $k, permissionDefault($role, $k) ? 1 : 0]);
    }
    forgetPermissions();
    $bad = matrixMismatches($who, $grantable, function ($k) use ($role) {
        return permissionDefault($role, $k);
    }, "override");
    check("matrix: $role - an individual override beats the role row on every key, both ways", $bad === [], implode("; ", $bad));

    // Inherit: deleting the override goes back to the role row.
    rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = ?", [$id]);
    $bad = matrixMismatches($who, $grantable, $flipped, "role");
    check("matrix: $role - inherit (no override) is the role row again", $bad === [], implode("; ", $bad));

    rbRows($pdo, "DELETE FROM role_permissions WHERE role = ?", [$role]);
    $bad = matrixMismatches($who, array_keys(PERMISSIONS), $default, "default");
    check("matrix: $role - with the role row gone it is the default again", $bad === [], implode("; ", $bad));
}

// The brief's named cases, on one key each.
rbRows($pdo, "INSERT INTO role_permissions (role, permission, allowed) VALUES ('account_manager', 'content.publish', 0)");
check("override: role Deny, no override - denied, from the role",
    permissionDecision(["kind" => "admin", "id" => 9613, "role" => "account_manager"], "content.publish", $pdo) === ["allowed" => false, "source" => "role"]);
rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (9613, 'content.publish', 1)");
check("override: an Allow beats a role-level Deny",
    permissionDecision(["kind" => "admin", "id" => 9613, "role" => "account_manager"], "content.publish", $pdo) === ["allowed" => true, "source" => "override"]);
rbRows($pdo, "UPDATE role_permissions SET allowed = 1 WHERE role = 'account_manager' AND permission = 'content.publish'");
rbRows($pdo, "UPDATE admin_permission_overrides SET allowed = 0 WHERE admin_id = 9613 AND permission = 'content.publish'");
check("override: a Deny beats a role-level Allow",
    permissionDecision(["kind" => "admin", "id" => 9613, "role" => "account_manager"], "content.publish", $pdo) === ["allowed" => false, "source" => "override"]);
check("override: one person's Deny leaves the rest of the role alone", can($cy, "content.publish", $pdo));
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 9613");
rbRows($pdo, "DELETE FROM role_permissions WHERE role = 'account_manager'");

check("matrix: the Director holds every key, from 'director'",
    matrixMismatches($director, array_keys(PERMISSIONS), function () { return true; }, "director") === []);
check("matrix: a client holds none, from 'system'",
    matrixMismatches(["kind" => "user", "id" => 9701, "role" => "client"], array_keys(PERMISSIONS), function () { return false; }, "system") === []);
check("matrix: an unknown or legacy role holds none",
    matrixMismatches(["kind" => "admin", "id" => 9611, "role" => "admin"], array_keys(PERMISSIONS), function () { return false; }, "system") === []);
check("matrix: a client claiming role owner is still a client",
    !can(["kind" => "user", "id" => 9701, "role" => "owner"], "settings.manage", $pdo));
$threw = false;
try {
    can($ada, "clients.everything", $pdo);
} catch (LogicException $e) {
    $threw = true;
}
check("matrix: an unknown key is a bug (exception), never a quiet yes", $threw);

// ── 2. Director-only keys ───────────────────────────────────────────────

$ownerOnly = array_values(array_filter(array_keys(PERMISSIONS), "isOwnerOnlyPermission"));
check("director tier: settings, admins and permissions are the Director-only keys",
    $ownerOnly === ["settings.manage", "admins.manage", "permissions.manage"], json_encode($ownerOnly));
foreach (PERMISSION_ROLES as $role) {
    foreach ($ownerOnly as $k) {
        $pdo->prepare("INSERT INTO role_permissions (role, permission, allowed) VALUES (?, ?, 1)")->execute([$role, $k]);
    }
}
forgetPermissions();
$leaks = [];
foreach ($matrixPeople as $role => $id) {
    foreach ($ownerOnly as $k) {
        if (can(["kind" => "admin", "id" => $id, "role" => $role], $k, $pdo)) {
            $leaks[] = "$role $k";
        }
    }
}
check("director tier: a stray role row grants no Director-only key to anyone", $leaks === [], implode(", ", $leaks));
rbRows($pdo, "DELETE FROM role_permissions WHERE permission IN ('settings.manage', 'admins.manage', 'permissions.manage')");

rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (1, 'clients.view_all', 0), (1, 'settings.manage', 0)");
check("director tier: the Director ignores Deny rows stored against them",
    can($director, "clients.view_all", $pdo) && can($director, "settings.manage", $pdo));
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 1");

// Pinned as built while Q1 (may the Director delegate these?) is open: one
// named person's Allow is the only way in. If Q1 locks permissions.manage,
// this check changes with it.
rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (9612, 'admins.manage', 1)");
check("director tier (Q1 open): only an individual Allow hands out a Director-only key",
    permissionDecision(["kind" => "admin", "id" => 9612, "role" => "super_admin"], "admins.manage", $pdo) === ["allowed" => true, "source" => "override"]
    && !can(["kind" => "admin", "id" => 9611, "role" => "seo_admin"], "admins.manage", $pdo));
check("director tier: whoever holds one is listed", directorPermissionsHeld(["kind" => "admin", "id" => 9612, "role" => "super_admin"], $pdo) === ["admins.manage"]);

// Put the role rows the earlier files stored back.
rbRows($pdo, "DELETE FROM role_permissions");
foreach ($savedRoleRows as $row) {
    $pdo->prepare("INSERT INTO role_permissions (role, permission, allowed, updated_by) VALUES (?, ?, ?, ?)")
        ->execute([$row["role"], $row["permission"], $row["allowed"], $row["updated_by"]]);
}
forgetPermissions();

// ── 3. Who may act on whom, and save what ───────────────────────────────

$delegate = ["kind" => "admin", "id" => 9612, "role" => "super_admin"]; // holds admins.manage now
$holder = ["id" => 9612, "role" => "super_admin"];
$plain = ["id" => 9611, "role" => "seo_admin"];
$ownerRow = ["id" => 1, "role" => "owner"];
$targetCases = [];
foreach (ADMIN_TARGET_ACTIONS as $action) {
    $targetCases[] = ["the Director's account ($action)", $director, $ownerRow, $action, false];
    $targetCases[] = ["the Director's account by a delegate ($action)", $delegate, $ownerRow, $action, false];
    $targetCases[] = ["a plain admin by the Director ($action)", $director, $plain, $action, true];
    $targetCases[] = ["a plain admin by a delegate ($action)", $delegate, $plain, $action, true];
    $targetCases[] = ["a holder of a Director-only key by the Director ($action)", $director, $holder, $action, true];
    $targetCases[] = ["themself ($action)", $delegate, $holder, $action, false];
    $targetCases[] = ["a plain admin themself ($action)", ["kind" => "admin", "id" => 9611, "role" => "seo_admin"], $plain, $action, $action === "edit"];
}
$wrong = [];
foreach ($targetCases as [$label, $actor, $target, $action, $allowed]) {
    if ((adminTargetRefusal($actor, $target, $action, $pdo) === null) !== $allowed) {
        $wrong[] = $label;
    }
}
check("targets: the Director is untouchable, nobody acts on themself (a key holder not even to edit), plain admins are fair game", $wrong === [], implode("; ", $wrong));

rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (9613, 'settings.manage', 1)");
$wrong = [];
foreach (ADMIN_TARGET_ACTIONS as $action) {
    if (adminTargetRefusal($delegate, ["id" => 9613, "role" => "account_manager"], $action, $pdo) === null) {
        $wrong[] = $action;
    }
}
check("targets: one delegate can't act on another holder of a Director-only key", $wrong === [], implode(", ", $wrong));
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 9613");

check("changes: a delegate can't hand out a Director-only key, to a person or a role",
    permissionChangeRefusal($delegate, ["permissions.manage" => true]) !== null
    && permissionChangeRefusal($delegate, ["settings.manage" => false], "seo_admin") !== null);
check("changes: nor change their own role's row", permissionChangeRefusal($delegate, ["clients.edit" => true], "super_admin") !== null);
check("changes: another role's ordinary key is theirs to change", permissionChangeRefusal($delegate, ["clients.edit" => true], "seo_admin") === null);
check("changes: the Director may change anything", permissionChangeRefusal($director, ["permissions.manage" => true], "super_admin") === null);

// ── 4. Data scope ───────────────────────────────────────────────────────

check("scope: the narrower levels are only own and assigned - no department level",
    array_values(array_unique(array_column(DATA_SCOPES, "narrow"))) === ["assigned", "own"], json_encode(array_column(DATA_SCOPES, "narrow")));

$levels = [];
foreach (array_keys(DATA_SCOPES) as $res) {
    $levels[$res] = [dataScope($pdo, $ada, $res), dataScope($pdo, $viv, $res),
        dataScope($pdo, ["kind" => "user", "id" => 9701, "role" => "client"], $res)];
}
check("scope: an Admin's levels, a view-all holder's, a client's", $levels === [
    "client" => ["assigned", "all", "none"], "response" => ["assigned", "all", "none"], "form" => ["own", "all", "none"],
    "content" => ["own", "all", "none"], "project" => ["assigned", "all", "none"],
], json_encode($levels));
check("scope: the level follows the key, not the role name", dataScope($pdo, $cy, "content") === "all");
rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (9603, 'content.view_all', 0)");
check("scope: an Account Manager whose content.view_all is denied sees their own posts only", dataScope($pdo, $cy, "content") === "own");
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 9603");

/** The fixture ids of $resource that $who's scopeSql() lets through. */
function scopedFixtureIds(array $who, $resource, array $among)
{
    global $pdo;
    $def = DATA_SCOPES[$resource];
    [$sql, $params] = scopeSql($pdo, $who, $resource, $def["alias"]);
    $marks = implode(",", array_fill(0, count($among), "?"));
    $stmt = $pdo->prepare("SELECT {$def["alias"]}.id FROM {$def["table"]} {$def["alias"]} WHERE {$def["alias"]}.id IN ($marks)"
        . ($sql !== "" ? " AND $sql" : "") . " ORDER BY {$def["alias"]}.id");
    $stmt->execute(array_merge($among, $params));
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$fixtures = [
    "client" => [[9701, 9702], [9701], [9702]],
    "response" => [[9721, 9722], [9721], [9722]],
    "form" => [[9711, 9712], [9711], [9712]],
    "content" => [[9731, 9732, 9733], [9731, 9733], [9732, 9733]],
    "project" => [[9741, 9742, 9743], [9741, 9743], [9742, 9743]],
];
foreach ($fixtures as $res => [$all, $adaSees, $benSees]) {
    check("scope $res: Ada's list (own / assigned)", scopedFixtureIds($ada, $res, $all) === $adaSees, json_encode(scopedFixtureIds($ada, $res, $all)));
    check("scope $res: Ben, in Ada's department, sees his own and not hers", scopedFixtureIds($ben, $res, $all) === $benSees, json_encode(scopedFixtureIds($ben, $res, $all)));
    check("scope $res: all records for the view-all holder", scopedFixtureIds($viv, $res, $all) === $all, json_encode(scopedFixtureIds($viv, $res, $all)));
    $byId = [];
    foreach ($all as $id) {
        $byId[$id] = canAccessRecord($pdo, $ada, $res, $id);
    }
    check("scope $res: canAccessRecord agrees with the list, id by id",
        array_keys(array_filter($byId)) === $adaSees, json_encode($byId));
    check("scope $res: a record that doesn't exist is out of scope", !canAccessRecord($pdo, $viv, $res, 9799));
    check("scope $res: a client principal gets nothing", scopedFixtureIds(["kind" => "user", "id" => 9701, "role" => "client"], $res, $all) === []);
}

// ── 5. AI scope ceiling ─────────────────────────────────────────────────

check("ai: every staff scope has a permission mapping", array_diff(STAFF_SCOPES, array_keys(SCOPE_PERMISSIONS)) === [],
    json_encode(array_values(array_diff(STAFF_SCOPES, array_keys(SCOPE_PERMISSIONS)))));
check("ai: every mapped key is a real permission", array_filter(SCOPE_PERMISSIONS, function ($k) {
    return $k !== null && !isPermission($k);
}) === []);
check("ai: every scope's page key is a real permission", array_filter(SCOPE_PAGES, function ($k) {
    return !isPermission($k);
}) === []);
check("ai: client scopes are never staff scopes", array_intersect(CLIENT_SCOPES, STAFF_SCOPES) === []);

/** Scopes $who would get that their permissions don't back. */
function scopeLeaks(array $who)
{
    global $pdo;
    $leaks = [];
    foreach (scopesAllowedFor($pdo, $who) as $scope) {
        $perm = array_key_exists($scope, SCOPE_PERMISSIONS) ? SCOPE_PERMISSIONS[$scope] : "unmapped";
        $page = scopePagePermission($scope);
        if ($perm === "unmapped" || ($perm !== null && !can($who, $perm, $pdo)) || ($page !== null && !can($who, $page, $pdo))) {
            $leaks[] = $scope;
        }
    }
    return $leaks;
}

// Every role, then everyone with a page and a key taken away.
$aiPeople = [$ada, $ben, $cy, $viv, ["kind" => "admin", "id" => 9612, "role" => "super_admin"]];
foreach ($aiPeople as $who) {
    check("ai: {$who["role"]} {$who["id"]} holds no scope beyond their permissions", scopeLeaks($who) === [], json_encode(scopeLeaks($who)));
}
rbRows($pdo, "INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES
    (9603, 'pages.content', 0), (9603, 'calendar.use', 0), (9603, 'reports.review', 0), (9603, 'pages.forms', 0)");
$cyScopes = scopesAllowedFor($pdo, $cy);
check("ai: closing pages and keys takes their scopes away", scopeLeaks($cy) === []
    && !array_intersect(["content:read", "content:write", "calendar:read", "calendar:write", "activity_reports:review", "forms:read", "team_forms:read"], $cyScopes),
    json_encode($cyScopes));
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 9603");
check("ai: the Director-only scopes go to the Director only",
    array_intersect(["settings:read", "settings:write", "admins:write"], scopesAllowedFor($pdo, $cy)) === []
    && array_intersect(["settings:read", "settings:write", "admins:write"], scopesAllowedFor($pdo, loadPrincipal($pdo, "admin", 1))) === ["settings:read", "settings:write", "admins:write"]);
check("ai: a client gets client scopes or nothing, never a staff one",
    array_intersect(scopesAllowedFor($pdo, ["kind" => "user", "id" => 9701, "role" => "client"]), STAFF_SCOPES) === []);

// Leave 9612's delegated key for nobody: rbac-security.php makes its own.
rbRows($pdo, "DELETE FROM admin_permission_overrides WHERE admin_id = 9612");
