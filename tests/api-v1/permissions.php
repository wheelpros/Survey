<?php

/*
| Permissions - required by run.php last, sharing its scope.
|
|   1. The defaults are exactly the role rules the endpoints hard-coded
|      before api/permissions.php: nothing changes until the Director does.
|   2. can() resolves an override, then the role, then the default.
*/

require_once $root . "/api/permissions.php";

// ── 1. The defaults are the old rules ────────────────────────────────────

/* What each role could do before - written out by hand, not read from
   PERMISSIONS, so changing a default is a deliberate edit in two places. */
$before = [
    "super_admin" => [
        "clients.details", "content.create", "projects.create", "tasks.write", "calendar.use",
    ],
    "account_manager" => [
        "clients.details", "clients.edit", "forms.review", "content.view_all", "content.create",
        "content.publish", "projects.create", "tasks.write", "tasks.edit_any", "tasks.see_drafts",
        "calendar.use",
    ],
    "seo_admin" => [],
];
foreach ($before as $role => $held) {
    $got = array_values(array_filter(array_keys(PERMISSIONS), function ($perm) use ($role) {
        return permissionDefault($role, $perm);
    }));
    sort($got);
    sort($held);
    check("permissions: $role defaults are the old rules", $got === $held, json_encode($got));
}

$olivia = ["kind" => "admin", "id" => 1, "role" => "owner"];
check("permissions: the Director holds every one", count(effectivePermissions($olivia)) === count(PERMISSIONS));
check("permissions: a client holds none", effectivePermissions(["kind" => "user", "id" => 10, "role" => "client"]) === []);
check("permissions: an unknown role holds none", effectivePermissions(["kind" => "admin", "id" => 99, "role" => "intern"]) === []);
$threw = false;
try {
    can($olivia, "content.typo");
} catch (LogicException $e) {
    $threw = true;
}
check("permissions: an unknown key is an error, not a no", $threw);

// The old inquiries_access flag became an override: Amy had it, Andy didn't.
$amy = ["kind" => "admin", "id" => 3, "role" => "account_manager"];
$andy = ["kind" => "admin", "id" => 4, "role" => "account_manager"];
$sam = ["kind" => "admin", "id" => 2, "role" => "super_admin"];
check("permissions: Amy kept inquiries access", can($amy, "inquiries.read"));
check("permissions: Andy still has none", !can($andy, "inquiries.read"));

// ── 2. Override, then role, then default ─────────────────────────────────

$pdo->exec("INSERT INTO role_permissions (role, permission, allowed) VALUES ('super_admin', 'content.create', 0)");
forgetPermissions();
check("permissions: a role row beats the default", !can($sam, "content.create"));

$pdo->exec("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (2, 'content.create', 1)");
forgetPermissions();
check("permissions: an admin's override beats the role", can($sam, "content.create"));

$pdo->exec("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (2, 'settings.manage', 1)");
forgetPermissions();
check("permissions: a Director-only key can't be handed out, even by a stray row", !can($sam, "settings.manage"));

$pdo->exec("DELETE FROM role_permissions");
$pdo->exec("DELETE FROM admin_permission_overrides WHERE admin_id = 2");
forgetPermissions();
check("permissions: back to the default", can($sam, "content.create"));

// ── 3. Settings > Permissions ────────────────────────────────────────────

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [$status, $r] = pageGet("api/permissions-admin.php", $token);
    check("permissions page: a $who can't open it", $status === 403 && ($r["success"] ?? null) === false, "HTTP $status " . json_encode($r));
}
[$status, $r] = pageGet("api/permissions-admin.php", $owner);
check("permissions page: the Director reads it", $status === 200
    && count($r["data"]["catalog"] ?? []) === count(PERMISSIONS)
    && ($r["data"]["matrix"]["super_admin"]["content.create"] ?? null) === true
    && !isset($r["data"]["matrix"]["super_admin"]["settings.manage"]), "HTTP $status " . json_encode($r));
$amyRow = array_values(array_filter($r["data"]["admins"] ?? [], function ($a) { return $a["id"] === 3; }))[0] ?? [];
check("permissions page: Amy's inquiries access shows as an exception",
    ($amyRow["overrides"]["inquiries.read"] ?? null) === true && in_array("inquiries.read", $amyRow["permissions"] ?? [], true), json_encode($amyRow));

// The Director takes content.create away from every Super Admin...
[$status, $r] = post("api/permissions-admin.php", $owner, ["role" => "super_admin", "changes" => ["content.create" => false]]);
check("permissions page: a role change saves", $status === 200 && ($r["success"] ?? false) === true
    && ($r["data"]["matrix"]["super_admin"]["content.create"] ?? null) === false, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/admin-content.php", $sa, ["title" => "Sam's post", "status" => "draft"], [], true);
check("permissions page: Sam can't create a post in the browser", $status === 403
    && !one($pdo, "SELECT id FROM content WHERE title = 'Sam''s post'"), "HTTP $status " . json_encode($r));
[, $me] = get("me.php", $sa);
check("permissions page: nor through an AI", !in_array("content:write", $me["data"]["scopes"] ?? [], true), json_encode($me));
[, $session] = post("api/admin-session.php", $sa, []);
check("permissions page: his pages hear about it", ($session["valid"] ?? false) === true
    && !in_array("content.create", $session["admin"]["permissions"] ?? ["content.create"], true), json_encode($session));
check("permissions page: the change is in the event log",
    (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'admin.role_permissions_changed' AND summary LIKE '%can no longer: create posts%'"));

// ...then gives it back to Sam alone: his exception beats the role.
[$status, $r] = post("api/permissions-admin.php", $owner, ["admin_id" => 2, "changes" => ["content.create" => true]]);
check("permissions page: an exception saves", $status === 200 && ($r["success"] ?? false) === true, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/admin-content.php", $sa, ["title" => "Sam's post", "status" => "draft"], [], true);
check("permissions page: Sam's exception beats his role", ($r["success"] ?? false) === true
    && one($pdo, "SELECT id FROM content WHERE title = 'Sam''s post'"), "HTTP $status " . json_encode($r));
check("permissions page: and is logged against him",
    (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'admin.permissions_changed' AND target_id = 2"));

// What can't be changed.
foreach ([
    "a Director-only permission" => [["role" => "super_admin", "changes" => ["settings.manage" => true]], 403],
    "the Director's role"        => [["role" => "owner", "changes" => ["content.create" => false]], 422],
    "the Director's own row"   => [["admin_id" => 1, "changes" => ["content.create" => false]], 403],
    "an unknown permission"      => [["role" => "seo_admin", "changes" => ["content.everything" => true]], 422],
    "a value that isn't one"     => [["role" => "seo_admin", "changes" => ["content.create" => "yes"]], 422],
    "nothing at all"             => [["role" => "seo_admin", "changes" => []], 422],
] as $what => [$body, $code]) {
    [$status, $r] = post("api/permissions-admin.php", $owner, $body);
    check("permissions page: refuses $what", $status === $code && ($r["success"] ?? null) === false, "HTTP $status " . json_encode($r));
}
[$status] = post("api/permissions-admin.php", $sa, ["admin_id" => 2, "changes" => ["settings.manage" => true]]);
check("permissions page: nobody else can save", $status === 403);
forgetPermissions();
check("permissions page: none of that stuck", !can(["id" => 2, "role" => "super_admin"], "settings.manage"));

// "default" puts things back, and a role change clears someone's exceptions.
post("api/permissions-admin.php", $owner, ["role" => "super_admin", "changes" => ["content.create" => "default"]]);
check("permissions page: a role back to its default leaves no row",
    !one($pdo, "SELECT role FROM role_permissions WHERE role = 'super_admin' AND permission = 'content.create'"));
post("api/permissions-admin.php", $owner, ["admin_id" => 4, "changes" => ["inquiries.read" => true, "tasks.edit_any" => false]]);
check("permissions page: Andy's exceptions saved, and his old flag follows",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_permission_overrides WHERE admin_id = 4")["n"] === 2
    && (int) one($pdo, "SELECT inquiries_access FROM admins WHERE id = 4")["inquiries_access"] === 1);
change("permissions page: Andy becomes a Super Admin", $owner, "change_admin_role", ["admin" => 4, "role" => "super_admin"]);
check("permissions page: and starts with no exceptions",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_permission_overrides WHERE admin_id = 4")["n"] === 0
    && (int) one($pdo, "SELECT inquiries_access FROM admins WHERE id = 4")["inquiries_access"] === 0);
change("permissions page: Andy goes back", $owner, "change_admin_role", ["admin" => 4, "role" => "account_manager"]);

// A deactivated admin keeps their exceptions but can do nothing with them.
post("api/permissions-admin.php", $owner, ["admin_id" => 6, "changes" => ["calendar.use" => true]]);
[$status] = get("calendar.php", "tok-inactive");
check("permissions page: a deactivated admin's exception gets them nowhere", $status === 401, "HTTP $status");
