<?php

/*
|--------------------------------------------------------------------------
| Permissions: what each admin may do, decided by the Director
|--------------------------------------------------------------------------
|
| Every rule that used to be a role list copied into an endpoint is one key
| here. Each key has a default - the roles that held it before this file
| existed - and the Director can change it in two places:
|
|   role_permissions            for everyone in a role
|   admin_permission_overrides  for one admin, beating their role
|
| can() resolves it in that order: the owner (the Director) holds every
| permission, a client none; otherwise the admin's own override, then their
| role's row, then the default below. Only rows the Director actually changed
| are stored, so a fresh install behaves exactly as the hard-coded rules did.
|
| A permission decides which *actions* someone may take. Which *records* they
| see within it (assigned clients, own posts, project membership) stays the
| endpoint's job, apart from the *.view_all keys, which lift that limit.
|
| `owner_only` keys are the Director's alone and cannot be handed out: running
| the portal, the team, and these permissions themselves.
|
*/

require_once __DIR__ . "/db.php";

/* The roles the Director can set permissions for, in the order Settings shows them:
   Admin, Super Admin, Account Manager. */
const PERMISSION_ROLES = ["seo_admin", "super_admin", "account_manager"];

const PERMISSION_AREAS = [
    "clients"   => "Clients",
    "forms"     => "Forms",
    "content"   => "Content",
    "projects"  => "Projects",
    "inquiries" => "Inquiries",
    "calendar"  => "Calendar",
    "team"      => "Team",
    "director"  => "Director only",
];

const PERMISSIONS = [
    // Clients
    "clients.view_all"   => ["area" => "clients", "label" => "See every client, not only their own", "roles" => []],
    "clients.details"    => ["area" => "clients", "label" => "Open Clients Management", "roles" => ["super_admin", "account_manager"]],
    "clients.edit"       => ["area" => "clients", "label" => "Edit client details and internal notes", "roles" => ["account_manager"]],
    "clients.approve"    => ["area" => "clients", "label" => "Approve or reject new registrations", "roles" => []],
    "clients.delete"     => ["area" => "clients", "label" => "Delete a client", "roles" => []],

    // Forms
    "forms.review"       => ["area" => "forms", "label" => "See every form and approve or return them", "roles" => ["account_manager"]],
    "forms.responses"    => ["area" => "forms", "label" => "See every client's answers", "roles" => []],

    // Content
    "content.view_all"   => ["area" => "content", "label" => "See every post", "roles" => ["account_manager"]],
    "content.create"     => ["area" => "content", "label" => "Create posts", "roles" => ["super_admin", "account_manager"]],
    "content.publish"    => ["area" => "content", "label" => "Schedule and publish, and change or delete anyone's post", "roles" => ["account_manager"]],

    // Projects
    "projects.view_all"  => ["area" => "projects", "label" => "See every project", "roles" => []],
    "projects.create"    => ["area" => "projects", "label" => "Create projects", "roles" => ["super_admin", "account_manager"]],
    "projects.manage_any" => ["area" => "projects", "label" => "Edit any project", "roles" => []],
    "projects.delete_any" => ["area" => "projects", "label" => "Delete any project", "roles" => []],
    "tasks.write"        => ["area" => "projects", "label" => "Post updates on any project they can see", "roles" => ["super_admin", "account_manager"]],
    "tasks.edit_any"     => ["area" => "projects", "label" => "Edit or delete a teammate's update", "roles" => ["account_manager"]],
    "tasks.see_drafts"   => ["area" => "projects", "label" => "See teammates' draft updates", "roles" => ["account_manager"]],

    // Inquiries - account managers get read access one by one, as an override
    "inquiries.read"     => ["area" => "inquiries", "label" => "See inquiries and their leads", "roles" => []],
    "inquiries.write"    => ["area" => "inquiries", "label" => "Create, edit and close inquiries", "roles" => []],

    // Calendar
    "calendar.use"       => ["area" => "calendar", "label" => "Use the calendar and book meetings", "roles" => ["super_admin", "account_manager"]],

    // Team
    "team.overview"      => ["area" => "team", "label" => "See the whole team's tasks on the dashboard", "roles" => []],
    "announcements.send" => ["area" => "team", "label" => "Send announcements", "roles" => []],
    "events.view"        => ["area" => "team", "label" => "Read the event log - every admin's changes", "roles" => []],

    // The Director's alone
    "settings.manage"    => ["area" => "director", "label" => "Change portal settings", "roles" => [], "owner_only" => true],
    "admins.manage"      => ["area" => "director", "label" => "Add, edit and remove admins", "roles" => [], "owner_only" => true],
    "permissions.manage" => ["area" => "director", "label" => "Change these permissions", "roles" => [], "owner_only" => true],
];

function isPermission($perm)
{
    return is_string($perm) && isset(PERMISSIONS[$perm]);
}

function isOwnerOnlyPermission($perm)
{
    return !empty(PERMISSIONS[$perm]["owner_only"]);
}

/** Whether $role held $perm before the Director changed anything. */
function permissionDefault($role, $perm)
{
    return in_array($role, PERMISSIONS[$perm]["roles"], true);
}

/**
 * [role rows, override rows] that apply to one admin, as permission => bool,
 * read once per request. forgetPermissions() drops it after a change.
 */
function permissionRows(?PDO $pdo, $role, $adminId, $forget = false)
{
    static $byRole = [];
    static $byAdmin = [];
    if ($forget) {
        $byRole = [];
        $byAdmin = [];
        return [[], []];
    }

    ensurePermissionTables($pdo);

    if (!array_key_exists($role, $byRole)) {
        $byRole[$role] = [];
        try {
            $stmt = $pdo->prepare("SELECT permission, allowed FROM role_permissions WHERE role = ?");
            $stmt->execute([$role]);
            foreach ($stmt->fetchAll() as $r) {
                $byRole[$role][$r["permission"]] = (int) $r["allowed"] === 1;
            }
        } catch (PDOException $e) {
            // No table yet (read-only DB user): every role keeps its defaults.
        }
    }

    $adminId = (int) $adminId;
    if (!array_key_exists($adminId, $byAdmin)) {
        $byAdmin[$adminId] = [];
        try {
            $stmt = $pdo->prepare("SELECT permission, allowed FROM admin_permission_overrides WHERE admin_id = ?");
            $stmt->execute([$adminId]);
            foreach ($stmt->fetchAll() as $r) {
                $byAdmin[$adminId][$r["permission"]] = (int) $r["allowed"] === 1;
            }
        } catch (PDOException $e) {
        }
    }

    return [$byRole[$role], $byAdmin[$adminId]];
}

function forgetPermissions()
{
    permissionRows(null, "", 0, true);
}

/**
 * May $who do $perm? $who is a principal (api/auth.php) or a plain admins
 * row - anything with "role" and "id". An unknown key is a bug, not a no.
 */
function can($who, $perm, ?PDO $pdo = null)
{
    if (!isPermission($perm)) {
        throw new LogicException("Unknown permission: $perm");
    }

    $role = (string) ($who["role"] ?? "");
    if (($who["kind"] ?? "admin") !== "admin" || $role === "" || $role === "client") {
        return false;
    }
    if ($role === "owner") {
        return true;
    }
    if (isOwnerOnlyPermission($perm) || !in_array($role, PERMISSION_ROLES, true)) {
        return false;
    }

    $pdo = $pdo ?? ($GLOBALS["pdo"] ?? null);
    if (!$pdo) {
        return permissionDefault($role, $perm);
    }

    [$roleRows, $overrides] = permissionRows($pdo, $role, (int) ($who["id"] ?? 0));
    if (array_key_exists($perm, $overrides)) {
        return $overrides[$perm];
    }
    if (array_key_exists($perm, $roleRows)) {
        return $roleRows[$perm];
    }
    return permissionDefault($role, $perm);
}

/** Every permission key $who holds - what the pages show and hide by. */
function effectivePermissions($who, ?PDO $pdo = null)
{
    return array_values(array_filter(array_keys(PERMISSIONS), function ($perm) use ($who, $pdo) {
        return can($who, $perm, $pdo);
    }));
}

/**
 * Stops a browser endpoint with the usual JSON refusal unless $who may do
 * $perm. Both keys are set because the older endpoints answer with "status"
 * and the newer ones with "success".
 */
function requirePermission($who, $perm, $message = "You do not have permission to do that.")
{
    if ($who && can($who, $perm)) {
        return;
    }
    if (!headers_sent()) {
        header("Content-Type: application/json");
    }
    echo json_encode(["success" => false, "status" => "error", "message" => $message]);
    exit;
}

/*
|--------------------------------------------------------------------------
| What the Settings page edits
|--------------------------------------------------------------------------
*/

/** The catalog as the Settings page draws it, owner_only keys included. */
function permissionCatalog()
{
    $out = [];
    foreach (PERMISSIONS as $key => $def) {
        $out[] = [
            "key" => $key,
            "area" => $def["area"],
            "area_label" => PERMISSION_AREAS[$def["area"]],
            "label" => $def["label"],
            "owner_only" => !empty($def["owner_only"]),
            "defaults" => array_values(array_filter(PERMISSION_ROLES, function ($role) use ($key) {
                return permissionDefault($role, $key);
            })),
        ];
    }
    return $out;
}

/** role => [permission => bool] for every grantable key: what each role has today. */
function rolePermissionMatrix(PDO $pdo)
{
    $stored = [];
    try {
        foreach ($pdo->query("SELECT role, permission, allowed FROM role_permissions")->fetchAll() as $r) {
            $stored[$r["role"]][$r["permission"]] = (int) $r["allowed"] === 1;
        }
    } catch (PDOException $e) {
    }

    $matrix = [];
    foreach (PERMISSION_ROLES as $role) {
        foreach (PERMISSIONS as $key => $def) {
            if (!empty($def["owner_only"])) {
                continue;
            }
            $matrix[$role][$key] = $stored[$role][$key] ?? permissionDefault($role, $key);
        }
    }
    return $matrix;
}

/** admin id => [permission => bool]: every override the Director set. */
function permissionOverrides(PDO $pdo, $adminId = null)
{
    $out = [];
    try {
        if ($adminId === null) {
            $rows = $pdo->query("SELECT admin_id, permission, allowed FROM admin_permission_overrides")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT admin_id, permission, allowed FROM admin_permission_overrides WHERE admin_id = ?");
            $stmt->execute([(int) $adminId]);
            $rows = $stmt->fetchAll();
        }
        foreach ($rows as $r) {
            if (isPermission($r["permission"])) {
                $out[(int) $r["admin_id"]][$r["permission"]] = (int) $r["allowed"] === 1;
            }
        }
    } catch (PDOException $e) {
    }
    return $out;
}

/*
|--------------------------------------------------------------------------
| Storing a change
|--------------------------------------------------------------------------
|
| $allowed true or false stores a row; null deletes it, so the role goes back
| to the default and the admin back to their role. The callers have checked
| who is asking (permissions.manage) and what is being changed, and have run
| ensurePermissionTables() before opening a transaction - a CREATE TABLE in
| MySQL commits whatever transaction is open.
|
*/

function storeRolePermission(PDO $pdo, $role, $perm, $allowed, $byAdminId)
{
    if ($allowed === null) {
        $pdo->prepare("DELETE FROM role_permissions WHERE role = ? AND permission = ?")->execute([$role, $perm]);
    } else {
        $pdo->prepare("
            INSERT INTO role_permissions (role, permission, allowed, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by)
        ")->execute([$role, $perm, $allowed ? 1 : 0, (int) $byAdminId]);
    }
    forgetPermissions();
}

function storeAdminPermission(PDO $pdo, $adminId, $perm, $allowed, $byAdminId)
{
    if ($allowed === null) {
        $pdo->prepare("DELETE FROM admin_permission_overrides WHERE admin_id = ? AND permission = ?")->execute([(int) $adminId, $perm]);
    } else {
        $pdo->prepare("
            INSERT INTO admin_permission_overrides (admin_id, permission, allowed, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by)
        ")->execute([(int) $adminId, $perm, $allowed ? 1 : 0, (int) $byAdminId]);
    }

    // The old flag mirrors an explicit grant, so ensurePermissionTables()
    // never brings back an override the Director removed, and an older build
    // still reads it right.
    if ($perm === "inquiries.read") {
        $pdo->prepare("UPDATE admins SET inquiries_access = ? WHERE id = ?")->execute([$allowed === true ? 1 : 0, (int) $adminId]);
    }
    forgetPermissions();
}
