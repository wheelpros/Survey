<?php

/*
|--------------------------------------------------------------------------
| Permissions migration, 2026-11: the rows behind roles and permissions
|--------------------------------------------------------------------------
|
| Part of the RBAC overhaul (docs/plans/2026-11-20-authorization-design.md,
| section 7). It removes rows the permission resolver never reads, rows for
| admins who no longer exist, and links and flags that disagree with the
| rules. It changes nothing about what anyone may do, and grants nothing.
|
|   php sql/rbac-2026-11.php                            dry run: lists every row it would change, writes nothing
|   php sql/rbac-2026-11.php --apply --database=NAME    makes the changes, in one transaction
|   php sql/rbac-2026-11.php --rollback=RUN --database=NAME
|   php sql/rbac-2026-11.php --runs                     the runs so far
|
|   --steps=a,b          only these steps (default: all of them)
|   --map-role=OLD=NEW   give the admins whose role is OLD the role NEW. NEW is
|                        seo_admin, super_admin or account_manager, never owner.
|                        Repeatable. Without it, legacy roles are only reported.
|   --skip-conflicts     rollback: put back what it can, and leave the rows
|                        someone has changed since
|   --json               print the report as JSON
|
| The database is DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS, as for the
| site (api/db.php). --apply and --rollback refuse to run unless --database
| repeats DB_NAME. Run it on a copy first.
|
| --apply checks its own work. Inside the transaction it reads, through the
| live resolver, every admin's permissions, team and assigned clients, before
| and after. If any of them moved it rolls everything back; the only
| exception is the admins --map-role names, whose new permissions it lists.
| Every changed row goes into rbac_migration_log with its old values, and
| --rollback restores them. Cleared session tokens are not kept anywhere, so
| a rollback leaves them cleared.
|
| The steps, in the order they run:
|
|   orphans            Rows for admins who no longer exist: permission
|                      overrides, client assignments, project memberships and
|                      post assignments (deleted), and their AI connections
|                      (revoked). Without this, a new admin who reused the id
|                      would inherit them.
|   dead_rows          Permission rows the resolver never reads (deleted): a
|                      key that isn't in PERMISSIONS, a role row for a role
|                      permissions don't apply to (owner included), a role row
|                      for a Director-only key, an override on the Director.
|   role_case          A role row stored as, say, "SEO_ADMIN" that the
|                      database already matches to seo_admin. It is renamed, and
|                      the effect is the same.
|   legacy_roles       Only with --map-role. The role is changed the way
|                      changeAdminRole() changes it: overrides cleared, session
|                      ended, one Tracking entry.
|   inquiries_flag     admins.inquiries_access, which older builds read, is set
|                      to match the inquiries.read override, the way
|                      storeAdminPermission() keeps it. A stale 1 on anyone but
|                      an Account Manager would otherwise become an inquiries
|                      grant on the day they were made one.
|   manager_links      managed_by_admin_id links the hierarchy ignores: to a
|                      missing admin, to themself, or a pair MANAGER_CAN_MANAGE
|                      doesn't allow. Each is cleared, with one Tracking entry.
|   inactive_sessions  Session tokens of deactivated admins are cleared.
|
| These are reported and never changed. They need the Director:
|   - Director-only keys handed to one admin (Q1 in the design).
|   - Admins whose role isn't one of the four. They hold no permissions today,
|     and --map-role is how the Director says what they should be.
|   - Manager links that involve such an admin.
|
| Defaults stay in code (PERMISSIONS) and are not copied into the tables. A
| stored copy would stop a later change to a default from reaching anyone.
| Rows that repeat a default, or repeat their role's value, are kept: each
| one was set on purpose. daily_report_reviewers is left alone: the app
| copies a report's original reviewer back in whenever a report has none.
*/

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$_SERVER["REQUEST_METHOD"] = $_SERVER["REQUEST_METHOD"] ?? "GET";
require __DIR__ . "/../api/db.php";
require_once __DIR__ . "/../api/team-scope.php";

const RBAC_MIGRATION = "rbac-2026-11";

const RBAC_STEPS = ["orphans", "dead_rows", "role_case", "legacy_roles", "inquiries_flag", "manager_links", "inactive_sessions"];

/* The roles admins.role may hold. Anything else is a legacy role. */
const RBAC_ROLES = ["owner", "super_admin", "account_manager", "seo_admin"];

/* The columns that pick out one row of each table this touches. */
const RBAC_ROW_KEYS = [
    "role_permissions" => ["role", "permission"],
    "admin_permission_overrides" => ["admin_id", "permission"],
    "admin_user_assignments" => ["admin_id", "user_id"],
    "project_members" => ["project_id", "admin_id"],
    "content_assignees" => ["content_id", "admin_id"],
    "mcp_grants" => ["id"],
    "admins" => ["id"],
];

/* What a legacy role is probably meant to be: a suggestion in the report, never applied. */
const RBAC_ROLE_LOOKALIKES = [
    "owner" => "owner", "director" => "owner",
    "super_admin" => "super_admin", "superadmin" => "super_admin",
    "account_manager" => "account_manager", "accountmanager" => "account_manager",
    "seo_admin" => "seo_admin", "seoadmin" => "seo_admin", "admin" => "seo_admin",
];

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

/** Rows, or none when the table doesn't exist (nothing in it to clean). */
function rbacRows(PDO $pdo, $sql, array $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ($e->getCode() === "42S02") {
            return [];
        }
        throw $e;
    }
}

function rbacKey($table, array $row)
{
    $key = [];
    foreach (RBAC_ROW_KEYS[$table] as $col) {
        $key[$col] = $row[$col];
    }
    return $key;
}

/**
 * [sql, params] matching exactly these values. Strings are compared byte for
 * byte, so "SEO_ADMIN" never stands in for "seo_admin", and NULL matches NULL.
 */
function rbacMatch(array $values)
{
    $parts = [];
    foreach (array_keys($values) as $col) {
        $parts[] = "BINARY `$col` <=> ?";
    }
    return [implode(" AND ", $parts), array_values($values)];
}

function rbacNotAKey($perm)
{
    $near = strtolower(trim((string) $perm));
    return isPermission($near)
        ? "\"$perm\" isn't a permission key. It looks like $near; set that in Settings if it was meant"
        : "\"$perm\" isn't a permission key";
}

function rbacLookalike($role)
{
    $key = preg_replace('/[\s-]+/', "_", strtolower(trim((string) $role)));
    return RBAC_ROLE_LOOKALIKES[$key] ?? RBAC_ROLE_LOOKALIKES[str_replace("_", "", $key)] ?? null;
}

function rbacLinkWhy($role, $managerRole)
{
    if ($role === "owner") {
        return "the Director answers to no manager";
    }
    if ($managerRole === "owner") {
        return "the Director manages everyone already";
    }
    if ($role === "account_manager") {
        return "an Account Manager answers only to the Director";
    }
    return "MANAGER_CAN_MANAGE doesn't let a $managerRole manage a $role";
}

/*
|--------------------------------------------------------------------------
| The plan: every row this run would change
|--------------------------------------------------------------------------
|
| A change is a delete (before = the whole row) or an update (before and
| after = the changed columns). It is read-only, and the dry run is exactly
| this. --apply builds it again inside its transaction.
|
*/

function rbacChange(array &$plan, $step, $op, $table, array $key, $before, $after, $why, $event = null, $restorable = true)
{
    $plan["changes"][] = [
        "step" => $step, "op" => $op, "table" => $table, "key" => $key,
        "before" => $before, "after" => $after, "why" => $why,
        "event" => $event, "restorable" => $restorable,
    ];
}

function rbacPlan(PDO $pdo, array $o)
{
    $plan = ["changes" => [], "decisions" => [], "mapped" => [], "notes" => []];
    $want = array_flip($o["steps"]);
    $now = date("Y-m-d H:i:s");

    $admins = [];
    foreach (rbacRows($pdo, "
        SELECT id, name, role, active, managed_by_admin_id, inquiries_access,
               session_token IS NOT NULL AS signed_in
        FROM admins ORDER BY id
    ") as $a) {
        $admins[(int) $a["id"]] = $a;
    }
    $name = function ($id) use ($admins) {
        return isset($admins[$id]) ? $admins[$id]["name"] . " (#$id)" : "admin #$id";
    };
    $overridesOf = [];
    foreach (rbacRows($pdo, "SELECT * FROM admin_permission_overrides ORDER BY admin_id, permission") as $row) {
        $overridesOf[(int) $row["admin_id"]][] = $row;
    }

    // ── orphans ──────────────────────────────────────────────────────────
    if (isset($want["orphans"])) {
        foreach (["admin_permission_overrides", "admin_user_assignments", "project_members", "content_assignees"] as $table) {
            foreach (rbacRows($pdo, "
                SELECT x.* FROM `$table` x LEFT JOIN admins a ON a.id = x.admin_id
                WHERE a.id IS NULL ORDER BY x.admin_id
            ") as $row) {
                rbacChange($plan, "orphans", "delete", $table, rbacKey($table, $row), $row, null,
                    "admin #" . (int) $row["admin_id"] . " no longer exists");
            }
        }
        foreach (rbacRows($pdo, "
            SELECT g.id, g.principal_id FROM mcp_grants g LEFT JOIN admins a ON a.id = g.principal_id
            WHERE g.principal_kind = 'admin' AND g.revoked_at IS NULL AND a.id IS NULL ORDER BY g.id
        ") as $g) {
            rbacChange($plan, "orphans", "update", "mcp_grants", ["id" => (int) $g["id"]],
                ["revoked_at" => null], ["revoked_at" => $now],
                "an AI connection of admin #" . (int) $g["principal_id"] . ", who no longer exists");
        }
    }

    // ── dead_rows and role_case: role rows ──────────────────────────────
    // The rows the resolver reads for each role, as the database itself
    // matches "role = ?", so its collation decides what counts as live.
    $live = [];
    foreach (PERMISSION_ROLES as $role) {
        foreach (rbacRows($pdo, "SELECT role, permission FROM role_permissions WHERE role = ?", [$role]) as $r) {
            $live[$r["role"] . "\0" . $r["permission"]] = $role;
        }
    }
    foreach (rbacRows($pdo, "SELECT * FROM role_permissions ORDER BY role, permission") as $row) {
        $role = $live[$row["role"] . "\0" . $row["permission"]] ?? null;
        if ($role === null) {
            $why = $row["role"] === "owner"
                ? "a role row for the Director, who holds every permission"
                : "\"" . $row["role"] . "\" isn't a role that permissions apply to";
        } elseif (!isPermission($row["permission"])) {
            $why = rbacNotAKey($row["permission"]);
        } elseif (isOwnerOnlyPermission($row["permission"])) {
            $why = "a Director-only key is never given by a role";
        } else {
            $why = null;
        }

        if ($why !== null && isset($want["dead_rows"])) {
            rbacChange($plan, "dead_rows", "delete", "role_permissions", rbacKey("role_permissions", $row), $row, null, $why);
        } elseif ($why === null && $row["role"] !== $role && isset($want["role_case"])) {
            rbacChange($plan, "role_case", "update", "role_permissions", rbacKey("role_permissions", $row),
                ["role" => $row["role"]], ["role" => $role], "the database already reads \"" . $row["role"] . "\" as $role");
        }
    }

    // ── legacy_roles: decided before the overrides, which they clear ─────
    $roleOf = [];
    $sessionEnded = [];
    foreach ($admins as $id => $a) {
        $roleOf[$id] = $a["role"];
        if (in_array($a["role"], RBAC_ROLES, true)) {
            continue;
        }
        $to = array_key_exists($a["role"], $o["map"]) ? $o["map"][$a["role"]] : null;
        if ($to === null || !isset($want["legacy_roles"])) {
            $like = rbacLookalike($a["role"]);
            $plan["decisions"][] = [
                "kind" => "legacy_role", "admin_id" => $id, "role" => $a["role"], "looks_like" => $like,
                "overrides" => count($overridesOf[$id] ?? []),
                "text" => $name($id) . " has the role \"" . $a["role"] . "\", which the portal doesn't know, so they hold no permissions."
                    . ($like === "owner" ? " It looks like the Director's; this migration never makes anyone the Director."
                        : ($like ? " It looks like $like: --map-role=\"" . $a["role"] . "=$like\"" : "")),
            ];
            continue;
        }

        $roleOf[$id] = $to;
        $plan["mapped"][$id] = $to;
        rbacChange($plan, "legacy_roles", "update", "admins", ["id" => $id], ["role" => $a["role"]], ["role" => $to],
            "--map-role: \"" . $a["role"] . "\" becomes $to",
            ["role", $id, "Changed " . $a["name"] . "'s role from \"" . $a["role"] . "\" to {new} (permissions migration)"]);
        foreach ($overridesOf[$id] ?? [] as $row) {
            rbacChange($plan, "legacy_roles", "delete", "admin_permission_overrides", rbacKey("admin_permission_overrides", $row), $row, null,
                "a role change starts them on their new role's permissions alone");
        }
        if ((int) $a["signed_in"] === 1) {
            $sessionEnded[$id] = true;
            rbacChange($plan, "legacy_roles", "update", "admins", ["id" => $id], null, ["session_token" => null],
                "a role change ends the session", null, false);
        }
    }
    foreach (array_keys($o["map"]) as $from) {
        if (!in_array($from, array_column($admins, "role"), true)) {
            $plan["notes"][] = "No admin has the role \"$from\"; --map-role for it changes nothing.";
        }
    }

    // ── dead_rows: overrides of admins who exist ────────────────────────
    if (isset($want["dead_rows"])) {
        foreach ($overridesOf as $id => $rows) {
            if (!isset($admins[$id]) || !in_array($admins[$id]["role"], RBAC_ROLES, true)) {
                continue; // orphans, or a legacy role: decided above
            }
            foreach ($rows as $row) {
                $why = $admins[$id]["role"] === "owner" ? "an override on the Director, who holds every permission"
                    : (!isPermission($row["permission"]) ? rbacNotAKey($row["permission"]) : null);
                if ($why !== null) {
                    rbacChange($plan, "dead_rows", "delete", "admin_permission_overrides", rbacKey("admin_permission_overrides", $row), $row, null, $why);
                }
            }
        }
    }

    // ── Director-only keys held by one admin: the Director decides (Q1) ──
    foreach ($overridesOf as $id => $rows) {
        if (!isset($admins[$id]) || !in_array($admins[$id]["role"], PERMISSION_ROLES, true)) {
            continue;
        }
        $held = [];
        foreach ($rows as $row) {
            if ((int) $row["allowed"] === 1 && isPermission($row["permission"]) && isOwnerOnlyPermission($row["permission"])) {
                $held[] = $row["permission"];
            }
        }
        if ($held) {
            $plan["decisions"][] = [
                "kind" => "director_tier", "admin_id" => $id, "permissions" => $held,
                "text" => $name($id) . " holds Director-only " . implode(", ", $held) . " by an individual override. Kept until Q1 is decided.",
            ];
        }
    }

    // ── inquiries_flag ───────────────────────────────────────────────────
    if (isset($want["inquiries_flag"])) {
        foreach ($admins as $id => $a) {
            if (!in_array($a["role"], PERMISSION_ROLES, true) && !isset($plan["mapped"][$id])) {
                continue; // the Director (always allowed), or a legacy role
            }
            $override = null;
            foreach ($overridesOf[$id] ?? [] as $row) {
                if ($row["permission"] === "inquiries.read") {
                    $override = (int) $row["allowed"] === 1;
                }
            }
            $flag = (int) $a["inquiries_access"] === 1;
            if (isset($plan["mapped"][$id])) {
                $override = null; // cleared with the role change
            } elseif ($a["role"] === "account_manager" && $flag && $override === null) {
                continue; // ensurePermissionTables() turns this into an Allow on the next request
            }
            $target = $override === true;
            if ($flag !== $target) {
                $why = $target ? "they hold an inquiries.read Allow; the old flag still says no"
                    : ($override === false ? "they hold an inquiries.read Deny; the old flag still says yes"
                        : "a leftover flag with no inquiries.read Allow behind it. It would turn into one if they were made an Account Manager");
                rbacChange($plan, "inquiries_flag", "update", "admins", ["id" => $id],
                    ["inquiries_access" => (int) $a["inquiries_access"]], ["inquiries_access" => $target ? 1 : 0], $why);
            }
        }
    }

    // ── manager_links ────────────────────────────────────────────────────
    if (isset($want["manager_links"])) {
        foreach ($admins as $id => $a) {
            if ($a["managed_by_admin_id"] === null) {
                continue;
            }
            $managerId = (int) $a["managed_by_admin_id"];
            if (!isset($admins[$managerId])) {
                $why = "their manager, admin #$managerId, no longer exists";
            } elseif ($managerId === $id) {
                $why = "they are set as their own manager";
            } elseif (!in_array($roleOf[$id], RBAC_ROLES, true) || !in_array($roleOf[$managerId], RBAC_ROLES, true)) {
                $plan["decisions"][] = [
                    "kind" => "manager_link", "admin_id" => $id, "manager_id" => $managerId,
                    "text" => $name($id) . " is managed by " . $name($managerId) . ", and one of them has a role the portal doesn't know. The link is kept.",
                ];
                continue;
            } elseif (!in_array($roleOf[$id], MANAGER_CAN_MANAGE[$roleOf[$managerId]] ?? [], true)) {
                $why = rbacLinkWhy($roleOf[$id], $roleOf[$managerId]);
            } else {
                continue;
            }
            rbacChange($plan, "manager_links", "update", "admins", ["id" => $id],
                ["managed_by_admin_id" => $managerId], ["managed_by_admin_id" => null], $why,
                ["manager", $id, "Removed " . $a["name"] . " from under " . $name($managerId) . ": $why (permissions migration)"]);
        }
    }

    // ── inactive_sessions ────────────────────────────────────────────────
    if (isset($want["inactive_sessions"])) {
        foreach ($admins as $id => $a) {
            // NULL is not deactivated: loadPrincipal() lets it in.
            if ($a["active"] !== null && (int) $a["active"] === 0 && (int) $a["signed_in"] === 1 && !isset($sessionEnded[$id])) {
                rbacChange($plan, "inactive_sessions", "update", "admins", ["id" => $id], null, ["session_token" => null],
                    "deactivated, but still holding a session token", null, false);
            }
        }
    }

    // In step order (usort keeps the order within a step).
    usort($plan["changes"], function ($a, $b) {
        return array_search($a["step"], RBAC_STEPS, true) <=> array_search($b["step"], RBAC_STEPS, true);
    });
    return $plan;
}

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

function rbacEnsureLog(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rbac_migration_log (
          id             BIGINT AUTO_INCREMENT PRIMARY KEY,
          run_id         VARCHAR(64)  NOT NULL,
          step           VARCHAR(32)  NOT NULL,
          op             VARCHAR(10)  NOT NULL,
          table_name     VARCHAR(64)  NOT NULL,
          row_key        TEXT         NOT NULL,
          before_row     MEDIUMTEXT       NULL,
          after_row      MEDIUMTEXT       NULL,
          restorable     TINYINT(1)   NOT NULL DEFAULT 1,
          note           VARCHAR(500) NOT NULL,
          created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          rolled_back_at DATETIME         NULL,
          KEY idx_run (run_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Columns MySQL would stamp on an UPDATE (ON UPDATE CURRENT_TIMESTAMP); an update here keeps them as they were. */
function rbacStampedColumns(PDO $pdo, $table)
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%on update%'
        ");
        $stmt->execute([$table]);
        $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return $cache[$table];
}

/**
 * One UPDATE of exactly one row: $set where the row has $key and, when
 * given, still holds $was. False when no such row is there any more.
 */
function rbacUpdate(PDO $pdo, $table, array $key, array $set, ?array $was)
{
    $sets = [];
    foreach (array_keys($set) as $col) {
        $sets[] = "`$col` = ?";
    }
    foreach (rbacStampedColumns($pdo, $table) as $col) {
        $sets[] = "`$col` = `$col`";
    }
    [$where, $params] = rbacMatch($key);
    if ($was !== null) {
        [$guard, $guardParams] = rbacMatch($was);
        $where .= " AND $guard";
        $params = array_merge($params, $guardParams);
    }
    $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(", ", $sets) . " WHERE $where");
    $stmt->execute(array_merge(array_values($set), $params));
    return $stmt->rowCount() === 1;
}

function rbacApplyChange(PDO $pdo, array $c)
{
    if ($c["op"] === "delete") {
        [$where, $params] = rbacMatch($c["before"]);
        $stmt = $pdo->prepare("DELETE FROM `{$c['table']}` WHERE $where");
        $stmt->execute($params);
        $done = $stmt->rowCount() === 1;
    } elseif ($c["before"] === null) {
        // A session token: only that there is one, never its value.
        $col = array_key_first($c["after"]);
        [$where, $params] = rbacMatch($c["key"]);
        $stmt = $pdo->prepare("UPDATE `{$c['table']}` SET `$col` = NULL WHERE $where AND `$col` IS NOT NULL");
        $stmt->execute($params);
        $done = $stmt->rowCount() === 1;
    } else {
        $done = rbacUpdate($pdo, $c["table"], $c["key"], $c["after"], $c["before"]);
    }
    if (!$done) {
        throw new RuntimeException("{$c['table']} " . json_encode($c["key"]) . " changed while this ran; nothing was written.");
    }
}

/** A Tracking entry (admin_events). The command line has no signed-in admin, so the actor is the migration. */
function rbacEvent(PDO $pdo, array $event, $runId)
{
    [$kind, $adminId, $summary] = $event;
    $action = $kind === "role" ? EVENT_ADMIN_ROLE : EVENT_ADMIN_MANAGER;
    $pdo->prepare("
        INSERT INTO admin_events (actor_admin_id, actor_name, actor_role, via, action, target_type, target_id, target_label, summary, request_id)
        VALUES (0, ?, 'system', 'cli', ?, 'admin', ?, ?, ?, ?)
    ")->execute([
        "Permissions migration", $action, (int) $adminId, mb_substr(eventAdminName($pdo, $adminId), 0, 200),
        mb_substr($summary, 0, 500), mb_substr($runId, 0, 64),
    ]);
}

/**
 * What every admin may do, who is on their team and which clients they're
 * assigned, read through the live resolver: [id => [...]]. A run must leave
 * it as it found it.
 */
function rbacAccess(PDO $pdo)
{
    forgetPermissions();
    $out = [];
    foreach ($pdo->query("SELECT id, role FROM admins ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $who = ["kind" => "admin", "id" => (int) $a["id"], "role" => (string) $a["role"]];
        $team = array_keys(teamPoolFor($pdo, $who));
        sort($team);
        $clients = rbacRows($pdo, "SELECT user_id FROM admin_user_assignments WHERE admin_id = ? ORDER BY user_id", [$who["id"]]);
        $out[$who["id"]] = [
            "permissions" => effectivePermissions($who, $pdo),
            "team" => $team,
            "clients" => array_map("intval", array_column($clients, "user_id")),
        ];
    }
    return $out;
}

/** The admins whose access moved, other than $mapped (their role changed on purpose): [id => [what, before, after]]. */
function rbacAccessMoved(array $before, array $after, array $mapped)
{
    $moved = [];
    $drop = function (array $ids) use ($mapped) {
        return array_values(array_diff($ids, $mapped));
    };
    foreach ($before + $after as $id => $unused) {
        if (in_array($id, $mapped, true)) {
            continue;
        }
        $was = $before[$id] ?? null;
        $now = $after[$id] ?? null;
        if ($was === null || $now === null) {
            $moved[$id] = ["admin", $was, $now];
            continue;
        }
        foreach (["permissions", "team", "clients"] as $what) {
            $a = $what === "team" ? $drop($was[$what]) : $was[$what];
            $b = $what === "team" ? $drop($now[$what]) : $now[$what];
            if ($a !== $b) {
                $moved[$id] = [$what, $a, $b];
            }
        }
    }
    return $moved;
}

function rbacApply(PDO $pdo, array $o)
{
    // Before the transaction: a CREATE TABLE commits whatever is open.
    require_once __DIR__ . "/../api/admin-settings-writes.php"; // event names, adminRoleName(); ensures admin_events
    ensurePermissionTables($pdo);
    rbacEnsureLog($pdo);

    $runId = RBAC_MIGRATION . "-" . gmdate("Ymd\THis\Z") . "-" . bin2hex(random_bytes(2));
    $pdo->beginTransaction();
    try {
        $before = rbacAccess($pdo);
        $plan = rbacPlan($pdo, $o);
        $log = $pdo->prepare("
            INSERT INTO rbac_migration_log (run_id, step, op, table_name, row_key, before_row, after_row, restorable, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($plan["changes"] as $c) {
            rbacApplyChange($pdo, $c);
            $log->execute([$runId, $c["step"], $c["op"], $c["table"], json_encode($c["key"]),
                $c["before"] === null ? null : json_encode($c["before"]), $c["after"] === null ? null : json_encode($c["after"]),
                $c["restorable"] ? 1 : 0, mb_substr($c["why"], 0, 500)]);
            if ($c["event"]) {
                $c["event"][2] = str_replace("{new}", adminRoleName($c["after"]["role"] ?? ""), $c["event"][2]);
                rbacEvent($pdo, $c["event"], $runId);
            }
        }

        $after = rbacAccess($pdo);
        $plan["moved"] = rbacAccessMoved($before, $after, array_keys($plan["mapped"]));
        foreach ($plan["mapped"] as $id => $to) {
            $plan["gained"][$id] = $after[$id]["permissions"] ?? [];
        }
        if ($plan["moved"]) {
            $pdo->rollBack();
            return [false, null, $plan];
        }
        $pdo->commit();
        return [true, $plan["changes"] ? $runId : null, $plan];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| Rolling a run back
|--------------------------------------------------------------------------
|
| Newest change first. A deleted row comes back only if nothing has taken its
| place, and an updated one only if it still holds what the run wrote. Any
| other case is a conflict: someone has changed it since, and their change
| wins. By default one conflict stops the whole rollback.
|
*/

function rbacRollback(PDO $pdo, array $o)
{
    require_once __DIR__ . "/../api/admin-settings-writes.php";
    ensurePermissionTables($pdo);
    rbacEnsureLog($pdo);

    $rows = rbacRows($pdo, "SELECT * FROM rbac_migration_log WHERE run_id = ? ORDER BY id DESC", [$o["run"]]);
    if (!$rows) {
        return [null, "There is no run " . $o["run"] . ". See --runs."];
    }
    if (array_filter(array_column($rows, "rolled_back_at"))) {
        return [null, "Run " . $o["run"] . " has already been rolled back."];
    }

    $result = ["restored" => [], "conflicts" => [], "skipped" => [], "moved" => []];
    $pdo->beginTransaction();
    try {
        $mapped = [];
        foreach ($rows as $r) {
            if ($r["step"] === "legacy_roles" && $r["table_name"] === "admins") {
                $mapped[] = (int) json_decode($r["row_key"], true)["id"];
            }
        }
        $mapped = array_values(array_unique($mapped));
        $accessBefore = rbacAccess($pdo);

        foreach ($rows as $r) {
            $key = json_decode($r["row_key"], true);
            $was = json_decode((string) $r["before_row"], true);
            $wrote = json_decode((string) $r["after_row"], true);
            $line = ["step" => $r["step"], "op" => $r["op"], "table" => $r["table_name"], "key" => $key, "why" => $r["note"]];

            if ((int) $r["restorable"] !== 1) {
                $result["skipped"][] = $line;
                continue;
            }
            if ($r["op"] === "delete") {
                [$where, $params] = rbacMatch(rbacKey($r["table_name"], $was));
                $stmt = $pdo->prepare("SELECT 1 FROM `{$r['table_name']}` WHERE $where LIMIT 1");
                $stmt->execute($params);
                if ($stmt->fetchColumn()) {
                    $result["conflicts"][] = $line + ["now" => "a row with this key exists again"];
                    continue;
                }
                $cols = array_keys($was);
                $pdo->prepare("INSERT INTO `{$r['table_name']}` (`" . implode("`, `", $cols) . "`) VALUES ("
                    . implode(", ", array_fill(0, count($cols), "?")) . ")")->execute(array_values($was));
            } else {
                // Found by what it is now: role_case renamed part of the key.
                $now = array_merge($key, array_intersect_key($wrote, $key));
                if (!rbacUpdate($pdo, $r["table_name"], $now, $was, $wrote)) {
                    $result["conflicts"][] = $line + ["now" => "changed since the run"];
                    continue;
                }
            }
            $result["restored"][] = $line;

            if ($r["table_name"] === "admins" && (array_key_exists("managed_by_admin_id", $was) || array_key_exists("role", $was))) {
                $adminId = (int) $key["id"];
                $who = eventAdminName($pdo, $adminId);
                rbacEvent($pdo, isset($was["role"])
                    ? ["role", $adminId, "Changed $who's role back to \"" . $was["role"] . "\" (rollback of " . $o["run"] . ")"]
                    : ["manager", $adminId, "Put $who back under " . eventAdminName($pdo, $was["managed_by_admin_id"]) . " (rollback of " . $o["run"] . ")"],
                    $o["run"]);
            }
        }

        $result["moved"] = rbacAccessMoved($accessBefore, rbacAccess($pdo), $mapped);
        if (($result["conflicts"] && !$o["skip_conflicts"]) || $result["moved"]) {
            $pdo->rollBack();
            return [false, $result];
        }
        $pdo->prepare("UPDATE rbac_migration_log SET rolled_back_at = NOW() WHERE run_id = ?")->execute([$o["run"]]);
        $pdo->commit();
        return [true, $result];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| The report
|--------------------------------------------------------------------------
*/

function rbacValue($v)
{
    return $v === null ? "NULL" : (is_string($v) && !is_numeric($v) ? "\"$v\"" : (string) $v);
}

function rbacDescribe(array $c)
{
    $key = [];
    foreach ($c["key"] as $col => $v) {
        $key[] = "$col=" . rbacValue($v);
    }
    $what = $c["op"] . " " . $c["table"] . " " . implode(" ", $key);
    if ($c["op"] === "delete") {
        $rest = array_diff_key($c["before"], $c["key"], ["updated_at" => 1, "updated_by" => 1, "created_at" => 1, "id" => 1]);
        foreach ($rest as $col => $v) {
            $what .= " $col=" . rbacValue($v);
        }
    } elseif (is_array($c["after"])) {
        foreach ($c["after"] as $col => $v) {
            $what .= ": $col " . ($c["before"] === null ? "(set)" : rbacValue($c["before"][$col])) . " -> " . rbacValue($v);
        }
    }
    return $what . "\n        " . $c["why"];
}

function rbacPrintPlan(array $plan, $heading)
{
    echo "$heading: " . count($plan["changes"]) . " row(s)\n";
    $step = null;
    foreach ($plan["changes"] as $c) {
        if ($c["step"] !== $step) {
            $step = $c["step"];
            $n = count(array_filter($plan["changes"], function ($x) use ($step) { return $x["step"] === $step; }));
            echo "\n  $step ($n)\n";
        }
        echo "    " . rbacDescribe($c) . "\n";
    }
    if ($plan["decisions"]) {
        echo "\nNeeds the Director, not changed: " . count($plan["decisions"]) . "\n";
        foreach ($plan["decisions"] as $d) {
            echo "  - " . $d["text"] . "\n";
        }
    }
    foreach ($plan["notes"] as $note) {
        echo "\nNote: $note\n";
    }
}

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

function rbacUsage($message)
{
    fwrite(STDERR, "$message\nUsage: php sql/rbac-2026-11.php [--apply --database=NAME | --rollback=RUN --database=NAME | --runs]"
        . " [--steps=a,b] [--map-role=OLD=NEW] [--skip-conflicts] [--json]\n");
    exit(2);
}

function rbacOptions(array $argv)
{
    $o = ["mode" => "dry", "database" => null, "run" => null, "steps" => RBAC_STEPS, "map" => [], "skip_conflicts" => false, "json" => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === "--apply" || $arg === "--runs") {
            $o["mode"] = substr($arg, 2);
        } elseif (preg_match('/^--rollback=(.+)$/', $arg, $m)) {
            $o["mode"] = "rollback";
            $o["run"] = $m[1];
        } elseif (preg_match('/^--database=(.+)$/', $arg, $m)) {
            $o["database"] = $m[1];
        } elseif (preg_match('/^--steps=(.+)$/', $arg, $m)) {
            $o["steps"] = explode(",", $m[1]);
            if (array_diff($o["steps"], RBAC_STEPS)) {
                rbacUsage("Unknown step: " . implode(", ", array_diff($o["steps"], RBAC_STEPS)) . ". The steps are " . implode(", ", RBAC_STEPS) . ".");
            }
        } elseif (preg_match('/^--map-role=(.*)=([^=]*)$/s', $arg, $m)) {
            if (in_array($m[1], RBAC_ROLES, true)) {
                rbacUsage("\"{$m[1]}\" is already a role. --map-role only maps roles the portal doesn't know.");
            }
            if (!in_array($m[2], PERMISSION_ROLES, true)) {
                rbacUsage("--map-role can give " . implode(", ", PERMISSION_ROLES) . ". It never makes anyone the Director.");
            }
            $o["map"][$m[1]] = $m[2];
        } elseif ($arg === "--skip-conflicts") {
            $o["skip_conflicts"] = true;
        } elseif ($arg === "--json") {
            $o["json"] = true;
        } else {
            rbacUsage("Unknown option: $arg");
        }
    }
    return $o;
}

$o = rbacOptions($argv);
$where = "$DB_NAME on $DB_HOST";

if (in_array($o["mode"], ["apply", "rollback"], true) && $o["database"] !== $DB_NAME) {
    rbacUsage("--" . $o["mode"] . " changes the database $where. Repeat its name to go ahead: --database=$DB_NAME");
}

if ($o["mode"] === "runs") {
    $runs = rbacRows($pdo, "
        SELECT run_id, MIN(created_at) AS at, COUNT(*) AS rows_changed, MAX(rolled_back_at) AS rolled_back_at
        FROM rbac_migration_log GROUP BY run_id ORDER BY MIN(id)
    ");
    if ($o["json"]) {
        echo json_encode(["database" => $DB_NAME, "runs" => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo $runs ? "" : "No runs in $where.\n";
        foreach ($runs as $r) {
            echo $r["run_id"] . "  " . $r["at"] . "  " . $r["rows_changed"] . " row(s)" . ($r["rolled_back_at"] ? "  rolled back " . $r["rolled_back_at"] : "") . "\n";
        }
    }
    exit(0);
}

if ($o["mode"] === "rollback") {
    [$ok, $result] = rbacRollback($pdo, $o);
    if ($ok === null) {
        rbacUsage($result);
    }
    if ($o["json"]) {
        echo json_encode(["mode" => "rollback", "database" => $DB_NAME, "run_id" => $o["run"], "ok" => $ok] + $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Permissions migration " . RBAC_MIGRATION . ": rollback of " . $o["run"] . " in $where\n\n";
        echo ($ok ? "Put back" : "Would put back") . ": " . count($result["restored"]) . " row(s)\n";
        foreach ($result["conflicts"] as $c) {
            echo "  conflict: " . $c["table"] . " " . json_encode($c["key"]) . " - " . $c["now"] . "\n";
        }
        foreach ($result["skipped"] as $c) {
            echo "  not restored, by design: " . $c["table"] . " " . json_encode($c["key"]) . " - " . $c["why"] . "\n";
        }
        foreach ($result["moved"] as $id => [$what]) {
            echo "  admin #$id: their $what would change\n";
        }
        echo "\n" . ($ok ? "Done." : "Nothing was changed." . ($result["conflicts"] ? " --skip-conflicts restores the rest and leaves these." : "")) . "\n";
    }
    exit($ok ? 0 : 1);
}

if ($o["mode"] === "apply") {
    try {
        [$ok, $runId, $plan] = rbacApply($pdo, $o);
    } catch (Throwable $e) {
        fwrite(STDERR, "Nothing was written: " . $e->getMessage() . "\n");
        exit(1);
    }
    if ($o["json"]) {
        echo json_encode(["mode" => "apply", "database" => $DB_NAME, "run_id" => $runId, "ok" => $ok] + $plan,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit($ok ? 0 : 1);
    }
    echo "Permissions migration " . RBAC_MIGRATION . " in $where\n\n";
    rbacPrintPlan($plan, $ok ? "Changed" : "Would have changed");
    if (!$ok) {
        echo "\nStopped, and nothing was written: these admins' access would have moved.\n";
        foreach ($plan["moved"] as $id => [$what, $was, $now]) {
            echo "  admin #$id $what: " . json_encode($was) . " -> " . json_encode($now) . "\n";
        }
        exit(1);
    }
    foreach ($plan["gained"] ?? [] as $id => $perms) {
        echo "\nadmin #$id now holds " . $plan["mapped"][$id] . "'s permissions: " . implode(", ", $perms) . "\n";
    }
    echo "\n" . ($plan["mapped"] ? "Everyone else's" : "Everyone's") . " permissions, team and clients are exactly as before.\n";
    echo $runId ? "Run $runId. To undo it: php sql/rbac-2026-11.php --rollback=$runId --database=$DB_NAME\n" : "Nothing to do.\n";
    exit(0);
}

// The dry run: SELECTs only.
$plan = rbacPlan($pdo, $o);
$matrix = $plan["mapped"] ? rolePermissionMatrix($pdo) : [];
foreach ($plan["mapped"] as $id => $to) {
    $plan["gained"][$id] = array_keys(array_filter($matrix[$to]));
}
if ($o["json"]) {
    echo json_encode(["mode" => "dry", "database" => $DB_NAME] + $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
echo "Permissions migration " . RBAC_MIGRATION . " in $where: DRY RUN, nothing was written\n\n";
rbacPrintPlan($plan, "Would change");
foreach ($plan["gained"] ?? [] as $id => $perms) {
    echo "\nadmin #$id would hold " . $plan["mapped"][$id] . "'s permissions: " . implode(", ", $perms) . "\n";
}
echo $plan["changes"] ? "\nTo make these changes: php sql/rbac-2026-11.php --apply --database=$DB_NAME"
    . ($o["steps"] !== RBAC_STEPS ? " --steps=" . implode(",", $o["steps"]) : "")
    . implode("", array_map(function ($from, $to) { return " --map-role=\"$from=$to\""; }, array_keys($o["map"]), $o["map"])) . "\n"
    : "\nNothing to do.\n";
