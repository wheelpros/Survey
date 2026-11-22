<?php

/*
| The permissions migration (sql/rbac-2026-11.php) - required by run.php,
| sharing its scope. It runs the real script, as a separate process, against
| this database. By now every other suite has left its rows here, so what the
| migration must leave alone is a realistic mix. Its own people and rows:
|
|   961 Mo    super_admin       under 962 (an SA can't manage an SA), stale inquiries flag
|   962 Mia   super_admin       under 969, who was deleted; holds admins.manage by override
|   963 Max   account_manager   under 961 (an AM answers only to the Director),
|                               inquiries.read Deny with the old flag still 1
|   964 Meg   seo_admin         deactivated but still signed in; under 963 (fine);
|                               a pages.files Deny that must stay
|   965 Ned   "Super Admin"     a role the portal doesn't know; under 963
|   966 Ola   seo_admin         their own manager; "Clients.Edit" override;
|                               inquiries.read Allow with the old flag still 0
|   969       -                 deleted: an override, a client, a project, a post
|                               and an AI connection still point at them
|
| Role rows nobody reads: owner, a role called "admin", a Director-only key,
| an unknown key. And one stored as "SEO_ADMIN" that the database matches to
| seo_admin, or doesn't, depending on the column's collation.
*/

require_once $root . "/api/team-scope.php";

seed($pdo, "admins", [
    ["id" => 961, "name" => "Mo", "email" => "mo@mig.test", "role" => "super_admin", "managed_by_admin_id" => 962, "inquiries_access" => 1],
    ["id" => 962, "name" => "Mia", "email" => "mia@mig.test", "role" => "super_admin", "managed_by_admin_id" => 969],
    ["id" => 963, "name" => "Max", "email" => "max@mig.test", "role" => "account_manager", "managed_by_admin_id" => 961, "inquiries_access" => 1],
    ["id" => 964, "name" => "Meg", "email" => "meg@mig.test", "role" => "seo_admin", "managed_by_admin_id" => 963,
     "active" => 0, "session_token" => "tok-mig-inactive"],
    ["id" => 965, "name" => "Ned", "email" => "ned@mig.test", "role" => "Super Admin", "managed_by_admin_id" => 963, "session_token" => "tok-mig-ned"],
    ["id" => 966, "name" => "Ola", "email" => "ola@mig.test", "role" => "seo_admin", "managed_by_admin_id" => 966],
]);
seed($pdo, "admin_permission_overrides", [
    ["admin_id" => 962, "permission" => "admins.manage", "allowed" => 1],
    ["admin_id" => 963, "permission" => "inquiries.read", "allowed" => 0],
    ["admin_id" => 964, "permission" => "pages.files", "allowed" => 0],
    ["admin_id" => 965, "permission" => "clients.view_all", "allowed" => 1],
    ["admin_id" => 966, "permission" => "Clients.Edit", "allowed" => 1],
    ["admin_id" => 966, "permission" => "inquiries.read", "allowed" => 1],
    ["admin_id" => 969, "permission" => "clients.view_all", "allowed" => 1],
    ["admin_id" => 1, "permission" => "clients.delete", "allowed" => 0],
]);
seed($pdo, "role_permissions", [
    ["role" => "owner", "permission" => "clients.edit", "allowed" => 0],
    ["role" => "admin", "permission" => "pages.files", "allowed" => 0],
    ["role" => "super_admin", "permission" => "admins.manage", "allowed" => 1],
    ["role" => "account_manager", "permission" => "no.such_key", "allowed" => 1],
    ["role" => "SEO_ADMIN", "permission" => "tasks.write", "allowed" => 1],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 969, "user_id" => 10]]);
seed($pdo, "project_members", [["project_id" => 500, "admin_id" => 969]]);
seed($pdo, "content_assignees", [["content_id" => 300, "admin_id" => 969]]);
seed($pdo, "mcp_grants", [["id" => 961, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 969, "scopes" => "clients:read"]]);

$ciRoles = (bool) preg_match('/_ci$/', (string) one($pdo, "
    SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions' AND COLUMN_NAME = 'role'
")["c"]);

/** Runs the migration: [exit code, decoded --json report, everything it printed]. */
function migrate(array $args)
{
    global $root;
    $proc = proc_open(array_merge([PHP_BINARY, $root . "/sql/rbac-2026-11.php"], $args, ["--json"]),
        [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $root);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, json_decode($out, true), trim($out . "\n" . $err)];
}

/** "step op table key" for each change, in order. */
function changeList(array $report)
{
    return array_map(function ($c) {
        return $c["step"] . " " . $c["op"] . " " . $c["table"] . " " . json_encode($c["key"]);
    }, $report["changes"] ?? []);
}

/** Every row the migration may touch. */
function migrationRows(PDO $pdo)
{
    $out = [];
    foreach ([
        "role_permissions" => "BINARY role, BINARY permission",
        "admin_permission_overrides" => "admin_id, BINARY permission",
        "admin_user_assignments" => "admin_id, user_id",
        "project_members" => "project_id, admin_id",
        "content_assignees" => "content_id, admin_id",
        "mcp_grants" => "id",
        "admins" => "id",
    ] as $table => $order) {
        $out[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY $order")->fetchAll();
    }
    return $out;
}

/** The tables that differ between two migrationRows() reads. */
function rowsDiffer(array $a, array $b)
{
    return implode(", ", array_filter(array_keys($a), function ($table) use ($a, $b) {
        return $a[$table] != $b[$table];
    }));
}

/** What each admin may do, manages and is assigned: read here, not through the script's own check. */
function migrationAccess(PDO $pdo, array $except = [])
{
    forgetPermissions();
    $out = [];
    foreach ($pdo->query("SELECT id, role FROM admins ORDER BY id")->fetchAll() as $a) {
        if (in_array((int) $a["id"], $except, true)) {
            continue;
        }
        $who = ["kind" => "admin", "id" => (int) $a["id"], "role" => $a["role"]];
        $team = array_values(array_diff(array_keys(teamPoolFor($pdo, $who)), $except));
        sort($team);
        $out[$who["id"]] = [effectivePermissions($who, $pdo), $team,
            $pdo->query("SELECT user_id FROM admin_user_assignments WHERE admin_id = " . $who["id"] . " ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN)];
    }
    return $out;
}

function adminRow(PDO $pdo, $id)
{
    return one($pdo, "SELECT role, active, managed_by_admin_id, inquiries_access, session_token FROM admins WHERE id = ?", [$id]);
}

$rowsBefore = migrationRows($pdo);
$accessBefore = migrationAccess($pdo);
$accessBeforeButNed = migrationAccess($pdo, [965]);

// ── The dry run ──────────────────────────────────────────────────────────

[$code, $dry, $out] = migrate([]);
check("migration: the dry run succeeds", $code === 0 && ($dry["mode"] ?? "") === "dry", $out);
check("migration: the dry run writes nothing", migrationRows($pdo) == $rowsBefore);
$planned = changeList($dry);

$roleCase = $ciRoles
    ? 'role_case update role_permissions {"role":"SEO_ADMIN","permission":"tasks.write"}'
    : 'dead_rows delete role_permissions {"role":"SEO_ADMIN","permission":"tasks.write"}';
foreach ([
    'orphans delete admin_permission_overrides {"admin_id":969,"permission":"clients.view_all"}',
    'orphans delete admin_user_assignments {"admin_id":969,"user_id":10}',
    'orphans delete project_members {"project_id":500,"admin_id":969}',
    'orphans delete content_assignees {"content_id":300,"admin_id":969}',
    'orphans update mcp_grants {"id":961}',
    'dead_rows delete role_permissions {"role":"owner","permission":"clients.edit"}',
    'dead_rows delete role_permissions {"role":"admin","permission":"pages.files"}',
    'dead_rows delete role_permissions {"role":"super_admin","permission":"admins.manage"}',
    'dead_rows delete role_permissions {"role":"account_manager","permission":"no.such_key"}',
    $roleCase,
    'dead_rows delete admin_permission_overrides {"admin_id":1,"permission":"clients.delete"}',
    'dead_rows delete admin_permission_overrides {"admin_id":966,"permission":"Clients.Edit"}',
    'inquiries_flag update admins {"id":961}',
    'inquiries_flag update admins {"id":963}',
    'inquiries_flag update admins {"id":966}',
    'manager_links update admins {"id":961}',
    'manager_links update admins {"id":962}',
    'manager_links update admins {"id":963}',
    'manager_links update admins {"id":966}',
    'inactive_sessions update admins {"id":964}',
] as $expected) {
    check("migration: the dry run lists $expected", in_array($expected, $planned, true), json_encode($planned));
}
foreach ([
    "an intentional Deny" => '{"admin_id":964,"permission":"pages.files"}',
    "a Director-only key held by override (Q1)" => '{"admin_id":962,"permission":"admins.manage"}',
    "a legacy role's override" => '{"admin_id":965,"permission":"clients.view_all"}',
] as $label => $key) {
    check("migration: the dry run keeps $label", !array_filter($planned, function ($p) use ($key) { return strpos($p, $key) !== false; }));
}
check("migration: a valid manager link is kept, and so is one involving a legacy role",
    !in_array('manager_links update admins {"id":964}', $planned, true) && !in_array('manager_links update admins {"id":965}', $planned, true));
check("migration: the legacy role isn't mapped unless asked", !array_filter($planned, function ($p) { return strpos($p, "legacy_roles") === 0; }));
check("migration: every change says why", count(array_filter($dry["changes"], function ($c) { return trim($c["why"]) !== ""; })) === count($dry["changes"]));

$byKind = function ($kind, $adminId) use ($dry) {
    foreach ($dry["decisions"] ?? [] as $d) {
        if ($d["kind"] === $kind && $d["admin_id"] === $adminId) {
            return $d;
        }
    }
    return null;
};
check("migration: Director-only keys held by one admin go to the Director (Q1)",
    ($byKind("director_tier", 962)["permissions"] ?? null) === ["admins.manage"], json_encode($dry["decisions"] ?? null));
check("migration: a legacy role is reported, with what it looks like",
    ($byKind("legacy_role", 965)["looks_like"] ?? null) === "super_admin" && ($byKind("legacy_role", 965)["overrides"] ?? null) === 1,
    json_encode($dry["decisions"] ?? null));
check("migration: so is a manager link involving one", $byKind("manager_link", 965) !== null);

[$code, $only] = migrate(["--steps=orphans"]);
check("migration: --steps limits the run", $code === 0 && $only["changes"]
    && !array_filter($only["changes"], function ($c) { return $c["step"] !== "orphans"; }), json_encode($only));

// ── Refusals ─────────────────────────────────────────────────────────────

[$code, , $out] = migrate(["--apply"]);
check("migration: --apply without naming the database is refused", $code === 2 && migrationRows($pdo) == $rowsBefore, $out);
[$code, , $out] = migrate(["--apply", "--database=other_test"]);
check("migration: --apply naming another database is refused", $code === 2 && migrationRows($pdo) == $rowsBefore, $out);
[$code, , $out] = migrate(["--map-role=Super Admin=owner"]);
check("migration: --map-role never makes anyone the Director", $code === 2, $out);
[$code, , $out] = migrate(["--map-role=seo_admin=super_admin"]);
check("migration: --map-role only maps roles the portal doesn't know", $code === 2, $out);
[$code, , $out] = migrate(["--steps=nope"]);
check("migration: an unknown step is refused", $code === 2, $out);

// ── Apply ────────────────────────────────────────────────────────────────

$eventsBefore = (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE actor_role = 'system'")["n"];
[$code, $applied, $out] = migrate(["--apply", "--database=$dbName"]);
$runId = $applied["run_id"] ?? "";
check("migration: --apply succeeds", $code === 0 && ($applied["ok"] ?? false) === true && $runId !== "", $out);
check("migration: --apply changes exactly the rows the dry run listed", changeList($applied) === $planned,
    json_encode(array_values(array_diff(changeList($applied), $planned))) . " / " . json_encode(array_values(array_diff($planned, changeList($applied)))));
check("migration: nobody's permissions, team or clients moved", migrationAccess($pdo) === $accessBefore);

check("migration: orphans are gone",
    !one($pdo, "SELECT 1 FROM admin_permission_overrides WHERE admin_id = 969")
    && !one($pdo, "SELECT 1 FROM admin_user_assignments WHERE admin_id = 969")
    && !one($pdo, "SELECT 1 FROM project_members WHERE admin_id = 969")
    && !one($pdo, "SELECT 1 FROM content_assignees WHERE admin_id = 969"));
check("migration: a deleted admin's AI connection is revoked", one($pdo, "SELECT revoked_at FROM mcp_grants WHERE id = 961")["revoked_at"] !== null);
check("migration: rows nobody reads are gone",
    !one($pdo, "SELECT 1 FROM role_permissions WHERE role IN ('owner', 'admin') OR permission IN ('admins.manage', 'no.such_key')")
    && !one($pdo, "SELECT 1 FROM admin_permission_overrides WHERE admin_id = 1")
    && !one($pdo, "SELECT 1 FROM admin_permission_overrides WHERE admin_id = 966 AND BINARY permission = 'Clients.Edit'"));
check("migration: a role row the database already matched is renamed, or gone where it never matched",
    $ciRoles ? (bool) one($pdo, "SELECT 1 FROM role_permissions WHERE BINARY role = 'seo_admin' AND permission = 'tasks.write'")
             : !one($pdo, "SELECT 1 FROM role_permissions WHERE permission = 'tasks.write'"));
check("migration: the old inquiries flag follows the override",
    (int) adminRow($pdo, 961)["inquiries_access"] === 0 && (int) adminRow($pdo, 963)["inquiries_access"] === 0
    && (int) adminRow($pdo, 966)["inquiries_access"] === 1);
check("migration: links the hierarchy ignores are cleared",
    adminRow($pdo, 961)["managed_by_admin_id"] === null && adminRow($pdo, 962)["managed_by_admin_id"] === null
    && adminRow($pdo, 963)["managed_by_admin_id"] === null && adminRow($pdo, 966)["managed_by_admin_id"] === null);
check("migration: a deactivated admin's session ends", adminRow($pdo, 964)["session_token"] === null);
check("migration: what needs the Director is untouched",
    (int) adminRow($pdo, 964)["managed_by_admin_id"] === 963 && (int) adminRow($pdo, 965)["managed_by_admin_id"] === 963
    && adminRow($pdo, 965)["role"] === "Super Admin" && adminRow($pdo, 965)["session_token"] === "tok-mig-ned"
    && (int) overrideRow($pdo, 962, "admins.manage")["allowed"] === 1 && (int) overrideRow($pdo, 964, "pages.files")["allowed"] === 0
    && (int) overrideRow($pdo, 965, "clients.view_all")["allowed"] === 1);
check("migration: every change is logged for the rollback",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM rbac_migration_log WHERE run_id = ?", [$runId])["n"] === count($planned));
check("migration: each cleared manager link is in Tracking",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE actor_role = 'system' AND action = 'admin.manager_changed'
                    AND target_id IN (961, 962, 963, 966) AND request_id = ?", [$runId])["n"] === 4);
$rowsApplied = migrationRows($pdo);

[$code, $again] = migrate([]);
check("migration: a second run finds nothing left to do", $code === 0 && ($again["changes"] ?? null) === [], json_encode(changeList($again ?? [])));
[$code, $noop] = migrate(["--apply", "--database=$dbName"]);
check("migration: applying it again changes nothing", $code === 0 && array_key_exists("run_id", $noop ?? []) && $noop["run_id"] === null
    && migrationRows($pdo) == $rowsApplied, json_encode($noop));

// ── Rollback ─────────────────────────────────────────────────────────────

// Cleared sessions are not kept, so they stay cleared: Meg's, and those of
// any deactivated admin an earlier suite left signed in.
$signedOut = array_map(function ($c) {
    return $c["key"]["id"];
}, array_values(array_filter($dry["changes"], function ($c) { return $c["step"] === "inactive_sessions"; })));

[$code, $rb, $out] = migrate(["--rollback=$runId", "--database=$dbName"]);
check("migration: the rollback succeeds", $code === 0 && ($rb["ok"] ?? false) === true
    && count($rb["restored"]) === count($planned) - count($signedOut) && count($rb["skipped"]) === count($signedOut), $out);

$expected = $rowsBefore;
foreach ($expected["admins"] as $i => $row) {
    if (in_array((int) $row["id"], $signedOut, true)) {
        $expected["admins"][$i]["session_token"] = null;
    }
}
$rowsRolledBack = migrationRows($pdo);
check("migration: the rollback puts every row back as it was, sessions apart", in_array(964, $signedOut, true)
    && $rowsRolledBack == $expected, rowsDiffer($rowsRolledBack, $expected));
check("migration: the rollback leaves everyone's access as it was", migrationAccess($pdo) === $accessBefore);
check("migration: a rolled-back run is marked",
    !one($pdo, "SELECT 1 FROM rbac_migration_log WHERE run_id = ? AND rolled_back_at IS NULL", [$runId]));
[$code, , $out] = migrate(["--rollback=$runId", "--database=$dbName"]);
check("migration: a run is rolled back only once", $code === 2, $out);
[$code, , $out] = migrate(["--rollback=no-such-run", "--database=$dbName"]);
check("migration: an unknown run is refused", $code === 2, $out);

// ── A rollback never undoes a change made since ─────────────────────────

[$code, $second] = migrate(["--apply", "--database=$dbName"]);
$secondRun = $second["run_id"] ?? "";
$pdo->exec("UPDATE admins SET managed_by_admin_id = 963 WHERE id = 961"); // the Director puts Mo under Max
$rowsChanged = migrationRows($pdo);

[$code, $rb, $out] = migrate(["--rollback=$secondRun", "--database=$dbName"]);
check("migration: a row changed since the run stops the rollback", $code === 1 && ($rb["ok"] ?? true) === false
    && array_column($rb["conflicts"], "key") === [["id" => 961]] && migrationRows($pdo) == $rowsChanged, $out);
[$code, $rb, $out] = migrate(["--rollback=$secondRun", "--database=$dbName", "--skip-conflicts"]);
check("migration: --skip-conflicts restores the rest and keeps the newer change", $code === 0
    && (int) adminRow($pdo, 961)["managed_by_admin_id"] === 963 && (int) adminRow($pdo, 962)["managed_by_admin_id"] === 969
    && (int) adminRow($pdo, 963)["managed_by_admin_id"] === 961, $out);
$pdo->exec("UPDATE admins SET managed_by_admin_id = 962 WHERE id = 961");

// ── --map-role: the Director says what a legacy role is ─────────────────

[$code, $dryMap, $out] = migrate(["--map-role=Super Admin=super_admin", "--steps=legacy_roles"]);
$mapList = changeList($dryMap ?? []);
check("migration: --map-role lists the role change, the overrides it clears and the session it ends", $code === 0 && $mapList === [
    'legacy_roles update admins {"id":965}',
    'legacy_roles delete admin_permission_overrides {"admin_id":965,"permission":"clients.view_all"}',
    'legacy_roles update admins {"id":965}',
], $out);
forgetPermissions();
$saHolds = effectivePermissions(["kind" => "admin", "id" => 0, "role" => "super_admin"], $pdo);
check("migration: the dry run says what they would hold", ($dryMap["gained"]["965"] ?? null) === $saHolds, json_encode($dryMap["gained"] ?? null));

[$code, $mapped, $out] = migrate(["--map-role=Super Admin=super_admin", "--steps=legacy_roles", "--apply", "--database=$dbName"]);
$mapRun = $mapped["run_id"] ?? "";
check("migration: --map-role applies", $code === 0 && adminRow($pdo, 965)["role"] === "super_admin"
    && adminRow($pdo, 965)["session_token"] === null && !overrideRow($pdo, 965, "clients.view_all"), $out);
forgetPermissions();
check("migration: ...they hold exactly a Super Admin's permissions, and nobody else moved",
    effectivePermissions(["kind" => "admin", "id" => 965, "role" => "super_admin"], $pdo) === $saHolds
    && migrationAccess($pdo, [965]) === $accessBeforeButNed);
check("migration: ...and the role change is in Tracking", (bool) one($pdo,
    "SELECT 1 FROM admin_events WHERE action = 'admin.role_changed' AND target_id = 965 AND request_id = ? AND summary LIKE '%to Super Admin%'", [$mapRun]));
check("migration: their link under an Account Manager is valid now, and kept", (int) adminRow($pdo, 965)["managed_by_admin_id"] === 963);

[$code, , $out] = migrate(["--rollback=$mapRun", "--database=$dbName"]);
check("migration: --map-role rolls back", $code === 0 && adminRow($pdo, 965)["role"] === "Super Admin"
    && (int) overrideRow($pdo, 965, "clients.view_all")["allowed"] === 1, $out);
check("migration: and afterwards everyone's access is as it began", migrationAccess($pdo) === $accessBefore);

// ── Clean up, so the suites after this one see what they expect ─────────

$pdo->exec("DELETE FROM admins WHERE id BETWEEN 961 AND 966");
$pdo->exec("DELETE FROM admin_permission_overrides WHERE admin_id IN (1, 961, 962, 963, 964, 965, 966, 969)");
$pdo->exec("DELETE FROM role_permissions WHERE role IN ('owner', 'admin') OR permission IN ('no.such_key', 'admins.manage')
            OR (BINARY role = 'SEO_ADMIN' AND permission = 'tasks.write')");
$pdo->exec("DELETE FROM admin_user_assignments WHERE admin_id = 969");
$pdo->exec("DELETE FROM project_members WHERE admin_id = 969");
$pdo->exec("DELETE FROM content_assignees WHERE admin_id = 969");
$pdo->exec("DELETE FROM mcp_grants WHERE id = 961");
forgetPermissions();
