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
| permissionDecision() resolves it in that order: the owner (the Director)
| holds every permission, a client none; otherwise the admin's own override,
| then their role's row, then the default below. can() is its yes/no. Only
| rows the Director actually changed are stored, so a fresh install behaves
| exactly as the hard-coded rules did.
|
| A permission decides which *actions* someone may take. Which *records* they
| see within it (assigned clients, own posts, project membership) stays the
| endpoint's job, apart from the *.view_all keys, which lift that limit.
|
| `owner_only` keys are the Director's: running the portal, the team, and
| these permissions themselves. No role can be given them, but the Director
| can hand one to a single admin from the "One person" tab - only that
| admin's own override counts, never a role row or a default.
|
*/

require_once __DIR__ . "/db.php";

/* The roles the Director can set permissions for, in the order Settings shows them:
   Admin, Super Admin, Account Manager. */
const PERMISSION_ROLES = ["seo_admin", "super_admin", "account_manager"];

/* One area per sidebar page, in sidebar order, then the two that belong to no
   page. Settings draws each page area headed by that page's switch
   (PERMISSION_AREA_PAGES), with what can be done on the page under it. */
const PERMISSION_AREAS = [
    "dashboard"     => "Dashboard",
    "forms"         => "Forms",
    "content"       => "Content",
    "projects"      => "Project Management",
    "inquiries"     => "Inquiries",
    "clients"       => "Clients Management",
    "files"         => "Files",
    "calendar"      => "Appointments",
    "messages"      => "Messages",
    "notifications" => "Notifications",
    "tracking"      => "Tracking",
    "reports"       => "Activity Reports",
    "everywhere"    => "Across all pages",
    "director"      => "Director only",
];

const ALL_ADMIN_ROLES = ["seo_admin", "super_admin", "account_manager"];

/* In PERMISSION_AREAS order, each page area starting with its "Open X" key:
   whether the page is in the sidebar and opens at all (assets/admin-nav.js
   redirects a page its admin can't open). The defaults are who could open
   each page before these existed. Four pages already had a key that did this
   job, so they keep it rather than getting a second switch. */
const PERMISSIONS = [
    "pages.dashboard"    => ["area" => "dashboard", "label" => "Open Dashboard", "roles" => ALL_ADMIN_ROLES],
    "team.overview"      => ["area" => "dashboard", "label" => "See the whole team's tasks on the dashboard", "roles" => []],

    "pages.forms"        => ["area" => "forms", "label" => "Open Forms", "roles" => ALL_ADMIN_ROLES],
    "forms.review"       => ["area" => "forms", "label" => "See every form and approve or return them", "roles" => ["account_manager"]],
    "forms.responses"    => ["area" => "forms", "label" => "See every client's answers", "roles" => []],
    // Team forms are the Forms page's Team Forms section.
    "team_forms.send"    => ["area" => "forms", "label" => "Send forms to the team", "roles" => []],
    "team_forms.responses" => ["area" => "forms", "label" => "See the answers to every team form, not only the ones they sent", "roles" => []],

    "pages.content"      => ["area" => "content", "label" => "Open Content", "roles" => ALL_ADMIN_ROLES],
    "content.view_all"   => ["area" => "content", "label" => "See every post", "roles" => ["account_manager"]],
    "content.create"     => ["area" => "content", "label" => "Create posts", "roles" => ["super_admin", "account_manager"]],
    "content.publish"    => ["area" => "content", "label" => "Schedule and publish, and change or delete anyone's post", "roles" => ["account_manager"]],

    "pages.projects"     => ["area" => "projects", "label" => "Open Project Management", "roles" => ALL_ADMIN_ROLES],
    "projects.view_all"  => ["area" => "projects", "label" => "See every project", "roles" => []],
    "projects.create"    => ["area" => "projects", "label" => "Create projects", "roles" => ["super_admin", "account_manager"]],
    "projects.manage_any" => ["area" => "projects", "label" => "Edit any project", "roles" => []],
    "projects.delete_any" => ["area" => "projects", "label" => "Delete any project", "roles" => []],
    // Being named a project's manager opens and edits it only while this holds.
    "projects.be_manager" => ["area" => "projects", "label" => "Be a project's manager", "roles" => ["account_manager"]],
    "tasks.write"        => ["area" => "projects", "label" => "Post updates on any project they can see", "roles" => ["super_admin", "account_manager"]],
    "tasks.edit_any"     => ["area" => "projects", "label" => "Edit or delete a teammate's update", "roles" => ["account_manager"]],
    "tasks.see_drafts"   => ["area" => "projects", "label" => "See teammates' draft updates", "roles" => ["account_manager"]],

    // Account managers get read access one by one, as an override.
    "inquiries.read"     => ["area" => "inquiries", "label" => "Open Inquiries and see their leads", "roles" => []],
    "inquiries.write"    => ["area" => "inquiries", "label" => "Create, edit and close inquiries", "roles" => []],

    "clients.details"    => ["area" => "clients", "label" => "Open Clients Management", "roles" => ["super_admin", "account_manager"]],
    "clients.edit"       => ["area" => "clients", "label" => "Edit client details and internal notes", "roles" => ["account_manager"]],
    "clients.approve"    => ["area" => "clients", "label" => "Approve or reject new registrations", "roles" => []],
    "clients.delete"     => ["area" => "clients", "label" => "Delete a client", "roles" => []],
    "clients.manage_accounts" => ["area" => "clients", "label" => "Change a client's owner or merge client accounts", "roles" => []],

    "pages.files"        => ["area" => "files", "label" => "Open Files", "roles" => ALL_ADMIN_ROLES],
    // The key keeps its old name: it is stored in role settings.
    "calendar.use"       => ["area" => "calendar", "label" => "Open Appointments and book meetings", "roles" => ["super_admin", "account_manager"]],
    "pages.messages"     => ["area" => "messages", "label" => "Open Messages", "roles" => ALL_ADMIN_ROLES],
    // Reading and writing the Messages chat of each client they are on the
    // team of (client-message-writes.php). The Director reads every client's
    // chat without it, and answers one only while it has nobody holding it.
    "messages.clients"   => ["area" => "messages", "label" => "Write to clients in Messages", "roles" => ["account_manager"]],

    "pages.notifications" => ["area" => "notifications", "label" => "Open Notifications", "roles" => ALL_ADMIN_ROLES],
    "announcements.send" => ["area" => "notifications", "label" => "Send announcements", "roles" => []],

    "events.view"        => ["area" => "tracking", "label" => "Open Tracking - every admin's changes and all AI activity", "roles" => []],

    // Filling in your own report needs none of these.
    "pages.daily_reports" => ["area" => "reports", "label" => "Open Activity Reports", "roles" => ALL_ADMIN_ROLES],
    "reports.create"     => ["area" => "reports", "label" => "Plan and review activity reports, and templates, for their team", "roles" => []],
    "reports.self"       => ["area" => "reports", "label" => "Plan their own activity report", "roles" => ["super_admin", "account_manager"]],
    "reports.review"     => ["area" => "reports", "label" => "Review their team's activity reports", "roles" => ["super_admin", "account_manager"]],
    "reports.view_all"   => ["area" => "reports", "label" => "See every activity report in every department", "roles" => []],

    // Which clients they see on Forms, Projects, Appointments and Clients
    // Management alike, so it belongs to no one page and no page switch stops it.
    "clients.view_all"   => ["area" => "everywhere", "label" => "See every client, not only their own", "roles" => []],

    // The Director's alone
    "settings.manage"    => ["area" => "director", "label" => "Change portal settings", "roles" => [], "owner_only" => true],
    "admins.manage"      => ["area" => "director", "label" => "Add, edit and remove admins", "roles" => [], "owner_only" => true],
    "permissions.manage" => ["area" => "director", "label" => "Change these permissions", "roles" => [], "owner_only" => true],
];

/* Keys Settings asks about before saving a grant: each one lifts the limit to
   the records someone sees (the *.view_all kind), or erases or rehomes them.
   The Director-only keys are on the list too, through owner_only. Only a
   prompt - saving is the same either way. */
const RISKY_PERMISSIONS = [
    "clients.view_all", "clients.delete", "clients.manage_accounts",
    "forms.review", "forms.responses", "content.view_all",
    "projects.view_all", "projects.delete_any",
    "team_forms.responses", "reports.view_all",
];

/* Each page area's "Open X" key: Settings draws it as the switch at the head
   of the area. */
const PERMISSION_AREA_PAGES = [
    "dashboard"     => "pages.dashboard",
    "forms"         => "pages.forms",
    "content"       => "pages.content",
    "projects"      => "pages.projects",
    "inquiries"     => "inquiries.read",
    "clients"       => "clients.details",
    "files"         => "pages.files",
    "calendar"      => "calendar.use",
    "messages"      => "pages.messages",
    "notifications" => "pages.notifications",
    "tracking"      => "events.view",
    "reports"       => "pages.daily_reports",
];

/* Page areas whose keys still work with the page off, so Settings must not
   grey them out or call them "No effect". Everywhere else, with "Open X" off,
   the browser endpoints refuse (requirePage() or the page key) and so does
   the AI connection (SCOPE_PAGES in auth.php). Clients Management: its keys
   still work through the AI with the page off - whether the page should
   close that too is the Director's decision, not made here. */
const PERMISSION_AREAS_PAGE_OPEN = ["clients"];

/** The "Open X" key $perm only works through, or null. */
function permissionPage($perm)
{
    $area = PERMISSIONS[$perm]["area"];
    if (in_array($area, PERMISSION_AREAS_PAGE_OPEN, true)) {
        return null;
    }
    $page = PERMISSION_AREA_PAGES[$area] ?? null;
    return $page === $perm ? null : $page;
}

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

/** Whether $who is the Director - the one role every permission check passes. */
function isDirector($who)
{
    return is_array($who) && ($who["kind"] ?? "admin") === "admin" && ($who["role"] ?? "") === "owner";
}

/**
 * [role rows, override rows] that apply to one admin, as permission => bool,
 * read once per request, or null when they couldn't be read. forgetPermissions()
 * drops them after a change.
 *
 * A table that isn't there yet (a read-only DB user before sql/permissions.sql
 * is run) holds no rows, so the defaults are right. Any other failure is null:
 * the Director's stored denials can't be seen, so falling back to the defaults
 * could hand back something they took away.
 */
function permissionRows(?PDO $pdo, $role, $adminId, $forget = false)
{
    static $byRole = [];
    static $byAdmin = [];
    if ($forget) {
        $byRole = [];
        $byAdmin = [];
        return null;
    }

    ensurePermissionTables($pdo);

    $read = function ($sql, $key) use ($pdo) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$key]);
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[$r["permission"]] = (int) $r["allowed"] === 1;
            }
            return $rows;
        } catch (PDOException $e) {
            return $e->getCode() === "42S02" ? [] : null;
        }
    };

    if (!array_key_exists($role, $byRole)) {
        $byRole[$role] = $read("SELECT permission, allowed FROM role_permissions WHERE role = ?", $role);
    }

    $adminId = (int) $adminId;
    if (!array_key_exists($adminId, $byAdmin)) {
        $byAdmin[$adminId] = $read("SELECT permission, allowed FROM admin_permission_overrides WHERE admin_id = ?", $adminId);
    }

    if ($byRole[$role] === null || $byAdmin[$adminId] === null) {
        return null;
    }
    return [$byRole[$role], $byAdmin[$adminId]];
}

function forgetPermissions()
{
    permissionRows(null, "", 0, true);
}

/**
 * May $who do $perm, and why: ["allowed" => bool, "source" => ...]. The one
 * place a permission is decided - can() is its yes/no, and the Settings page
 * shows the source beside each switch. In order:
 *
 *   system    not an admin, an unknown role, or the rows couldn't be read: no
 *   director  the Director: yes
 *   override  this admin's own Allow or Deny
 *   role      their role's row, which the Director set
 *   default   the shipped default for their role (the page shows this and
 *             "role" alike, as "from role")
 *
 * A Director-only key ("owner_only") counts an override and nothing else, so
 * no role row or default ever hands one out. $who is a principal
 * (api/auth.php) or a plain admins row - anything with "role" and "id". An
 * unknown key is a bug, not a no.
 */
function permissionDecision($who, $perm, ?PDO $pdo = null)
{
    if (!isPermission($perm)) {
        throw new LogicException("Unknown permission: $perm");
    }

    $no = ["allowed" => false, "source" => "system"];

    $role = (string) ($who["role"] ?? "");
    if (($who["kind"] ?? "admin") !== "admin" || $role === "" || $role === "client") {
        return $no;
    }
    if ($role === "owner") {
        return ["allowed" => true, "source" => "director"];
    }
    if (!in_array($role, PERMISSION_ROLES, true)) {
        return $no;
    }

    $pdo = $pdo ?? ($GLOBALS["pdo"] ?? null);
    if (!$pdo) {
        return isOwnerOnlyPermission($perm) ? $no : ["allowed" => permissionDefault($role, $perm), "source" => "default"];
    }

    $rows = permissionRows($pdo, $role, (int) ($who["id"] ?? 0));
    if ($rows === null) {
        return $no;
    }
    [$roleRows, $overrides] = $rows;

    if (array_key_exists($perm, $overrides)) {
        return ["allowed" => $overrides[$perm], "source" => "override"];
    }
    if (isOwnerOnlyPermission($perm)) {
        return ["allowed" => false, "source" => "default"];
    }
    if (array_key_exists($perm, $roleRows)) {
        return ["allowed" => $roleRows[$perm], "source" => "role"];
    }
    return ["allowed" => permissionDefault($role, $perm), "source" => "default"];
}

/** May $who do $perm? permissionDecision() without the reason. */
function can($who, $perm, ?PDO $pdo = null)
{
    return permissionDecision($who, $perm, $pdo)["allowed"];
}

/** The Director-only keys $who holds - by an override, since nothing else gives one. */
function directorPermissionsHeld($who, ?PDO $pdo = null)
{
    return array_values(array_filter(array_keys(PERMISSIONS), function ($perm) use ($who, $pdo) {
        return isOwnerOnlyPermission($perm) && can($who, $perm, $pdo);
    }));
}

/*
|--------------------------------------------------------------------------
| Which admin an admin action may touch
|--------------------------------------------------------------------------
|
| Not permissions: no key or override switches these. Whoever holds
| admins.manage or permissions.manage - the Director, or someone the Director
| handed one to - is still held to them:
|
|   - nobody changes the Director's role, permissions, active state or
|     manager, edits the Director here, or removes them;
|   - nobody changes their own role, permissions, active state or manager,
|     or removes themself - that would be granting yourself access;
|   - only the Director acts on an admin who holds a Director-only key, so
|     two people handed one can't undo each other or the Director's choice.
|
| $action is one of: edit, role, permissions, active, manager, remove.
| Returns why not, or null when the action may go ahead.
|
*/

const ADMIN_TARGET_ACTIONS = ["edit", "role", "permissions", "active", "manager", "remove"];

function adminTargetRefusal($actor, array $target, $action, ?PDO $pdo = null)
{
    if (!in_array($action, ADMIN_TARGET_ACTIONS, true)) {
        throw new LogicException("Unknown admin action: $action");
    }

    if (($target["role"] ?? "") === "owner") {
        return [
            "edit" => "The Director's account cannot be edited here.",
            "role" => "The Director's role cannot be changed here.",
            "permissions" => "The Director holds every permission.",
            "active" => "The Director's account can't be deactivated.",
            "manager" => "The Director answers to no manager.",
            "remove" => "You cannot remove the Director.",
        ][$action];
    }

    if ($action !== "edit" && (int) ($target["id"] ?? 0) === (int) ($actor["id"] ?? -1)) {
        return [
            "role" => "You cannot change your own role.",
            "permissions" => "You cannot change your own permissions.",
            "active" => "You can't deactivate your own account",
            "manager" => "You cannot change who manages you, or manage yourself.",
            "remove" => "You cannot remove yourself.",
        ][$action];
    }

    if (!isDirector($actor) && directorPermissionsHeld($target, $pdo)) {
        return "Only the Director can change an admin who holds a Director-only permission.";
    }

    return null;
}

/**
 * Why $actor may not save these permission changes, or null. $changes is
 * permission => true/false/null, already checked by checkPermissionChanges();
 * $role is the role row being changed, or null for one admin's overrides.
 *
 * Only the Director hands out a Director-only key. And nobody but the
 * Director changes the row of their own role: that is changing their own
 * permissions by another door.
 */
function permissionChangeRefusal($actor, array $changes, $role = null)
{
    if (isDirector($actor)) {
        return null;
    }
    foreach (array_keys($changes) as $perm) {
        if (isOwnerOnlyPermission($perm)) {
            return "Only the Director can give or take “" . PERMISSIONS[$perm]["label"] . "”.";
        }
    }
    if ($role !== null && $role === ($actor["role"] ?? "")) {
        return "You cannot change the permissions of your own role.";
    }
    return null;
}

/** Every permission key $who holds - what the pages show and hide by. */
function effectivePermissions($who, ?PDO $pdo = null)
{
    return array_values(array_filter(array_keys(PERMISSIONS), function ($perm) use ($who, $pdo) {
        return can($who, $perm, $pdo);
    }));
}

/**
 * Ends a browser request with the usual JSON refusal: 401 for no usable
 * session, 403 for a session that may not do this, 404 for a record outside
 * what the caller may see. Both keys are set because the older endpoints
 * answer with "status" and the newer ones with "success".
 */
function denyRequest($code, $message)
{
    http_response_code((int) $code);
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode(["success" => false, "status" => "error", "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Stops a browser endpoint with a 403 unless $who may do $perm. */
function requirePermission($who, $perm, $message = "You do not have permission to do that.")
{
    if ($who && can($who, $perm)) {
        return;
    }
    denyRequest($who ? 403 : 401, $who ? $message : "Unauthorized");
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
            "risky" => !empty($def["owner_only"]) || in_array($key, RISKY_PERMISSIONS, true),
            // The page it works through, and for a page the area it heads.
            "page" => permissionPage($key),
            "heads_area" => array_search($key, PERMISSION_AREA_PAGES, true) ?: null,
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
