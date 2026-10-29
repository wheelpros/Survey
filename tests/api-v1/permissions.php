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
