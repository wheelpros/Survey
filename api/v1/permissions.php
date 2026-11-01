<?php

/*
|--------------------------------------------------------------------------
| GET api/v1/permissions.php - get_permissions
|--------------------------------------------------------------------------
|
| Settings > Permissions as data: every permission key, what each role gets,
| and each admin's exceptions and what they end up with. The change tools
| set_role_permissions and set_admin_permissions take these keys.
|
| Scope settings:read and permissions.manage - the Director's alone. Admin
| names were typed by people, so they come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../admin-settings-writes.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "settings:read");

if (!can($p, "permissions.manage", $pdo)) {
    v1Error(403, "insufficient_scope", "Only the Director can see permissions.");
}

$o = permissionsOverview($pdo);

v1Reply(200, [
    "permissions" => array_map(function ($c) {
        return [
            "key" => $c["key"],
            "area" => $c["area"],
            "label" => $c["label"],
            "director_only" => $c["owner_only"],
            "default_roles" => $c["defaults"],
        ];
    }, $o["catalog"]),
    "roles" => array_map(function ($r) use ($o) {
        return [
            "role" => $r["key"],
            "role_name" => $r["name"],
            "allowed" => array_keys(array_filter($o["matrix"][$r["key"]])),
        ];
    }, $o["roles"]),
    "admins" => array_map(function ($a) {
        return [
            "id" => $a["id"],
            "role" => $a["role"],
            "role_name" => $a["role_name"],
            "active" => $a["active"],
            "exceptions" => array_map(function ($key, $allowed) {
                return ["key" => $key, "allowed" => $allowed];
            }, array_keys((array) $a["overrides"]), array_values((array) $a["overrides"])),
            "effective" => $a["permissions"],
            "untrusted_content" => ["name" => (string) $a["name"]],
        ];
    }, $o["admins"]),
]);
