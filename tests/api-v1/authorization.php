<?php

/*
| Authorization - required by run.php, sharing its scope. The rules every
| endpoint now goes through: api/auth.php requireAdmin(), permissions.php
| (permissionDecision(), adminTargetRefusal(), permissionChangeRefusal()) and
| scope.php. Its own people and records, so nothing earlier changes them:
|
|   871 Del   super_admin       handed admins.manage and permissions.manage
|   872 Pia   seo_admin
|   873 Ann   account_manager   assigned client 875, wrote form 8750
|   874 Ken   account_manager   assigned nobody
|   875 Zed   client            answered form 8750 (response 8760)
*/

seed($pdo, "admins", [
    ["id" => 871, "name" => "Del", "email" => "del@w.test", "role" => "super_admin", "session_token" => "tok-del"],
    ["id" => 872, "name" => "Pia", "email" => "pia@w.test", "role" => "seo_admin", "session_token" => "tok-pia"],
    ["id" => 873, "name" => "Ann", "email" => "ann@w.test", "role" => "account_manager", "session_token" => "tok-ann"],
    ["id" => 874, "name" => "Ken", "email" => "ken@w.test", "role" => "account_manager", "session_token" => "tok-ken"],
]);
seed($pdo, "admin_permission_overrides", [
    ["admin_id" => 871, "permission" => "admins.manage", "allowed" => 1],
    ["admin_id" => 871, "permission" => "permissions.manage", "allowed" => 1],
]);
seed($pdo, "users", [["id" => 875, "name" => "Zed", "email" => "zed@z.test", "approved" => 1, "company_name" => "Zed Co"]]);
seed($pdo, "admin_user_assignments", [["admin_id" => 873, "user_id" => 875]]);
seed($pdo, "surveys", [["id" => 8750, "title" => "Zed intake", "assigned_user_id" => 875, "status" => "completed", "created_by_admin_id" => 873]]);
seed($pdo, "survey_responses", [["id" => 8760, "user_id" => 875, "survey_id" => 8750, "survey_title" => "Zed intake"]]);

function overrideRow(PDO $pdo, $adminId, $perm)
{
    return one($pdo, "SELECT allowed FROM admin_permission_overrides WHERE admin_id = ? AND permission = ?", [$adminId, $perm]);
}

/** A download link the browser opens: [status, body text]. */
function linkGet($path)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["ignore_errors" => true, "timeout" => 10],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), (string) $body];
}

// ── Who is calling: 401 and 403 ─────────────────────────────────────────

[$status] = pageGet("api/admin-users.php", "");
check("authz: no token is a 401", $status === 401, "HTTP $status");
[$status] = pageGet("api/admin-users.php", "tok-nobody");
check("authz: an unknown token is a 401", $status === 401, "HTTP $status");
[$status] = pageGet("api/admin-users.php", "tok-client");
check("authz: a client's session on an admin endpoint is a 403", $status === 403, "HTTP $status");
[$status] = pageGet("api/admin-users.php", $mcpToken);
check("authz: an AI (MCP) token on a browser endpoint is a 401", $status === 401, "HTTP $status");
[$status] = pageGet("api/get-admins.php", "tok-pia");
check("authz: the Admins list needs admins.manage", $status === 403, "HTTP $status");
[$status] = post("api/admin-login-slides.php", "tok-pia", ["action" => "reorder", "ids" => []]);
check("authz: an Admin can't change the login page", $status === 403, "HTTP $status");

// ── Director-only rules hold against someone handed the keys ───────────

[, $r] = post("api/settings.php", "tok-del", ["action" => "toggle_admin_status", "id" => 1, "active" => 0]);
check("director: a delegate can't deactivate the Director (browser)",
    ($r["success"] ?? true) === false && (int) one($pdo, "SELECT active FROM admins WHERE id = 1")["active"] === 1, json_encode($r));
refusedChange("director: a delegate can't deactivate the Director (AI)", "tok-del", "set_admin_active", ["admin" => 1, "active" => false], 403);

[$status] = post("api/permissions-admin.php", "tok-del", ["admin_id" => 871, "changes" => ["clients.view_all" => true]]);
check("escalation: no one changes their own permissions", $status === 403 && !overrideRow($pdo, 871, "clients.view_all"), "HTTP $status");
[$status] = post("api/permissions-admin.php", "tok-del", ["admin_id" => 872, "changes" => ["admins.manage" => true]]);
check("escalation: only the Director hands out a Director-only key", $status === 403 && !overrideRow($pdo, 872, "admins.manage"), "HTTP $status");
[$status] = post("api/permissions-admin.php", "tok-del", ["role" => "super_admin", "changes" => ["clients.view_all" => true]]);
check("escalation: no one changes their own role's row", $status === 403
    && !one($pdo, "SELECT 1 FROM role_permissions WHERE role = 'super_admin' AND permission = 'clients.view_all'"), "HTTP $status");
refusedChange("escalation: nor through the AI connection", "tok-del", "set_admin_permissions",
    ["admin" => 871, "changes" => ["clients.view_all" => true]], 403);
refusedChange("hierarchy: a delegate can't make themself a manager", "tok-del", "assign_admins_to_manager",
    ["manager" => 871, "admins" => [872]], 403);

[$status, $r] = post("api/permissions-admin.php", "tok-del", ["admin_id" => 872, "changes" => ["clients.details" => true]]);
check("delegation: a grantable key for someone else still saves", $status === 200 && (int) (overrideRow($pdo, 872, "clients.details")["allowed"] ?? 0) === 1,
    "HTTP $status " . json_encode($r));
[$status, $r] = post("api/permissions-admin.php", $owner, ["admin_id" => 872, "changes" => ["clients.details" => "default"]]);
check("override: \"default\" deletes the row (inherit)", $status === 200 && !overrideRow($pdo, 872, "clients.details"), "HTTP $status");

// ── Deactivation ends the session ──────────────────────────────────────

[, $r] = post("api/settings.php", $owner, ["action" => "toggle_admin_status", "id" => 872, "active" => 0]);
check("deactivate: the Director can", ($r["success"] ?? false) === true, json_encode($r));
check("deactivate: the session token is cleared", one($pdo, "SELECT session_token FROM admins WHERE id = 872")["session_token"] === null);
[$status] = pageGet("api/admin-users.php", "tok-pia");
check("deactivate: the old token is a 401", $status === 401, "HTTP $status");

// ── Data scope: direct ids outside it are not found ────────────────────

[$status] = pageGet("api/admin-user-details.php?id=875", "tok-ken");
check("scope: a client not assigned to them is a 404", $status === 404, "HTTP $status");
[$status] = pageGet("api/admin-user-details.php?id=875", "tok-ann");
check("scope: their assigned client opens", $status === 200, "HTTP $status");
[$status] = post("api/admin-user-details.php", "tok-ken", ["action" => "save_details", "id" => 875, "company_name" => "Hacked"]);
check("scope: nor can they edit it", $status === 404 && one($pdo, "SELECT company_name FROM users WHERE id = 875")["company_name"] === "Zed Co", "HTTP $status");

[$status] = pageGet("api/response-details.php?id=8760", "tok-ken");
check("scope: a response outside theirs is a 404", $status === 404, "HTTP $status");
[$status] = pageGet("api/response-details.php?id=8760", "tok-ann");
check("scope: a response from their client opens", $status === 200, "HTTP $status");
[, $r] = pageGet("api/admin-responses.php", "tok-ken");
check("scope: the responses list leaves it out (and loads)", ($r["success"] ?? false) === true
    && !in_array(8760, array_map("intval", array_column($r["responses"] ?? [], "id")), true), json_encode($r));
[, $r] = pageGet("api/admin-responses.php", "tok-ann");
check("scope: and shows it to whoever may see it", in_array(8760, array_map("intval", array_column($r["responses"] ?? [], "id")), true), json_encode($r));

[$status] = linkGet("api/download-response-excel.php?id=8760&token=tok-ken");
check("files: the answers export refuses a response outside theirs", $status === 404, "HTTP $status");
[$status] = linkGet("api/download-response-excel.php?id=8760&token=tok-ann");
check("files: and allows their own", $status === 200, "HTTP $status");

[$status, $r] = get("responses.php?id=8760", "tok-ken");
check("scope: the AI connection follows the same response rule", $status === 404, "HTTP $status");
[$status] = get("responses.php?id=8760", "tok-ann");
check("scope: and lets their own through", $status === 200, "HTTP $status");
