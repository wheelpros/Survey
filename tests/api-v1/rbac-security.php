<?php

/*
| Security over HTTP - required by run.php after rbac-matrix.php, whose people
| and records it reuses (Ada 9601, Ben 9602, Cy 9603, Viv 9604; clients 9701
| and 9702). What a caller sending requests by hand can and can't do:
|
|   1. Every admin endpoint refuses no session, a dead session, a client's
|      session and an AI token (401/403), whatever the method.
|   2. Records outside the caller's scope, by id: read, edit and delete.
|   3. Files: attachments, form uploads, the Files page.
|   4. AI connections follow their person's permissions on every request.
|   5. Privilege escalation: roles, self-service, managers.
|   6. Bulk saves and the management hierarchy (no cycles).
|
|   9605 Del   super_admin       handed admins.manage; managed by 9606
|   9606 Xan   account_manager
|   9607 Yu    super_admin
|   9608 Pat   seo_admin         handed settings.manage; managed by 9606
|   9609 Sid   seo_admin         managed by 9606
|   9610 Dot   seo_admin         deactivated
*/

seed($pdo, "admins", [
    ["id" => 9605, "name" => "Del", "email" => "del@rb.test", "role" => "super_admin", "session_token" => "tok-rb-del", "managed_by_admin_id" => 9606],
    ["id" => 9606, "name" => "Xan", "email" => "xan@rb.test", "role" => "account_manager", "session_token" => "tok-rb-xan"],
    ["id" => 9607, "name" => "Yu", "email" => "yu@rb.test", "role" => "super_admin", "session_token" => "tok-rb-yu"],
    ["id" => 9608, "name" => "Pat", "email" => "pat@rb.test", "role" => "seo_admin", "session_token" => "tok-rb-pat", "managed_by_admin_id" => 9606],
    ["id" => 9609, "name" => "Sid", "email" => "sid@rb.test", "role" => "seo_admin", "session_token" => "tok-rb-sid", "managed_by_admin_id" => 9606],
    ["id" => 9610, "name" => "Dot", "email" => "dot@rb.test", "role" => "seo_admin", "session_token" => "tok-rb-dot", "active" => 0],
]);
seed($pdo, "admin_permission_overrides", [
    ["admin_id" => 9605, "permission" => "admins.manage", "allowed" => 1],
    ["admin_id" => 9608, "permission" => "settings.manage", "allowed" => 1],
]);
// Earlier files sign the Director out; this file has its own session for them.
$pdo->exec("UPDATE admins SET session_token = 'tok-rb-owner' WHERE id = 1");
$rbOwner = "tok-rb-owner";
forgetPermissions();

$rbAda = "tok-rb-ada"; $rbBen = "tok-rb-ben"; $rbCy = "tok-rb-cy"; $rbDel = "tok-rb-del";
$rbDead = "tok-rb-dot"; $rbClient = "tok-rb-client";

// Ada's AI connection.
$rbMcp = "wzat_" . str_repeat("r", 43);
seed($pdo, "mcp_grants", [["id" => 9601, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 9601,
    "scopes" => "clients:read", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($rbMcp), "kind" => "access", "grant_id" => 9601, "expires_at" => time() + 3600]]);
$viaMcp = ["X-MCP-Key" => $mcpKey];

/** Any method on a browser endpoint: [status, decoded body, raw body]. */
function rbSend($method, $path, $token, $body = null, array $headers = [])
{
    global $port;
    $lines = $token !== "" ? ["Authorization: Bearer $token"] : [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $opts = ["method" => $method, "ignore_errors" => true, "timeout" => 20];
    if ($body !== null) {
        $lines[] = "Content-Type: application/json";
        $opts["content"] = json_encode($body);
    }
    $opts["header"] = implode("\r\n", $lines);
    $raw = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create(["http" => $opts]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $raw, true), (string) $raw];
}

/** A refusal: the status is one of $codes and nothing reports success. */
function rbRefused($label, array $reply, array $codes)
{
    [$status, $body, $raw] = $reply;
    check($label, in_array($status, $codes, true) && ($body["success"] ?? null) !== true && ($body["ok"] ?? null) !== true,
        "HTTP $status " . substr($raw, 0, 200));
}

// ── 1. Every admin endpoint, every way in that isn't a live admin ───────

$adminEndpoints = [
    "add-user.php", "admin-content.php", "admin-dashboard.php", "admin-events.php", "admin-inquiries.php",
    "admin-inquiry-responses.php", "admin-login-slides.php", "admin-manager-assignments.php", "admin-messages.php",
    "admin-position.php", "admin-responses.php", "admin-role.php", "admin-survey-review.php", "admin-surveys.php",
    "admin-update.php", "admin-user-details.php?id=9701", "admin-users.php", "activity-reports.php?action=mine",
    "announcements.php", "calendar.php", "client-team.php?user_id=9701", "content-types.php",
    "delete-client.php", "departments.php", "get-admins.php", "inquiries-access.php", "notifications.php",
    "permissions-admin.php", "project-tasks.php", "projects.php", "remove-admin.php", "response-details.php?id=9721",
    "settings.php", "source-files.php", "source-folders.php", "sources-lock.php", "team-forms.php",
    "update-my-profile.php", "upload-logo.php", "verify-source-password.php",
];
// Clients use these too (their own meetings, notifications, announcements).
$sharedWithClients = ["announcements.php", "calendar.php", "notifications.php"];
// POST only: a GET is turned away (405) before anyone is looked up.
$postOnly = ["activity-report-writes.php"];
// Older replies: no session is a 200 carrying success:false, not a 401.
// Reported, not changed here - their pages read the body, not the status.
$legacyNoSession = ["calendar.php", "notifications.php"];
foreach (array_merge($adminEndpoints, $postOnly) as $ep) {
    foreach (in_array($ep, $postOnly, true) ? ["POST"] : ["GET", "POST"] as $method) {
        $body = $method === "POST" ? ["action" => "noop"] : null;
        rbRefused("sweep $method $ep: no session is a 401", rbSend($method, "api/$ep", "", $body), in_array($ep, $legacyNoSession, true) ? [200, 401] : [401]);
        rbRefused("sweep $method $ep: a deactivated admin is a 401", rbSend($method, "api/$ep", $rbDead, $body), [401]);
        if (!in_array($ep, $sharedWithClients, true)) {
            rbRefused("sweep $method $ep: a client's session is refused", rbSend($method, "api/$ep", $rbClient, $body), [401, 403]);
        }
        rbRefused("sweep $method $ep: an AI token is refused", rbSend($method, "api/$ep", $rbMcp, $body), [401, 403]);
    }
}
foreach (["download-response-excel.php?id=9721", "download-survey-file.php?id=1", "download-source-file.php?id=1"] as $ep) {
    rbRefused("sweep link $ep: no session is a 401", rbSend("GET", "api/$ep", ""), [401]);
    rbRefused("sweep link $ep: a deactivated admin is a 401", rbSend("GET", "api/$ep&token=$rbDead", ""), [401]);
}

// The same over api/v1, the AI connection's door.
foreach (glob(dirname(__DIR__, 2) . "/api/v1/*.php") as $file) {
    $ep = basename($file);
    if ($ep[0] === "_") {
        continue;
    }
    rbRefused("sweep v1 $ep: no token is a 401", rbSend("GET", "api/v1/$ep", ""), [401]);
    rbRefused("sweep v1 $ep: a deactivated admin is a 401", rbSend("GET", "api/v1/$ep", $rbDead), [401]);
    rbRefused("sweep v1 $ep: an AI token without the server's key is a 401", rbSend("GET", "api/v1/$ep", $rbMcp), [401]);
    if ($ep !== "me.php") {
        rbRefused("sweep v1 $ep: a client's token on a staff area is refused", rbSend("GET", "api/v1/$ep", $rbClient), [401, 403, 405]);
    }
}

// ── 2. Records outside the caller's scope, by id ────────────────────────

rbRefused("direct id: another admin's client (details)", rbSend("GET", "api/admin-user-details.php?id=9702", $rbAda), [403, 404]);
rbRefused("direct id: another account manager's client (details)", rbSend("GET", "api/admin-user-details.php?id=9702", $rbCy), [404]);
rbRefused("direct id: another admin's response", rbSend("GET", "api/response-details.php?id=9722", $rbAda), [404]);
[, $r] = rbSend("GET", "api/admin-surveys.php?survey_id=9712", $rbAda);
check("direct id: another admin's form doesn't open", ($r["success"] ?? null) === false && !isset($r["survey"]), json_encode($r));
rbRefused("direct id: nor can it be edited", rbSend("PUT", "api/admin-surveys.php", $rbAda, ["surveyId" => 9712, "title" => "Taken",
    "assignedUserId" => 9702, "questions" => [["text" => "Q", "type" => "input"]]]), [200, 403, 404]);
check("direct id: the form is unchanged", one($pdo, "SELECT title FROM surveys WHERE id = 9712")["title"] === "Ben form");

rbRefused("direct id: another admin's post", rbSend("GET", "api/admin-content.php?id=9732", $rbAda), [404]);
rbRefused("direct id: nor delete it", rbSend("DELETE", "api/admin-content.php?id=9732", $rbAda), [403, 404]);
check("direct id: the post is still there", (bool) one($pdo, "SELECT 1 FROM content WHERE id = 9732"));
rbRefused("direct id: an assignee can open a post but not delete it", rbSend("DELETE", "api/admin-content.php?id=9733", $rbAda), [403]);
check("direct id: that post is still there too", (bool) one($pdo, "SELECT 1 FROM content WHERE id = 9733"));

rbRefused("direct id: a project outside theirs", rbSend("GET", "api/projects.php?id=9742", $rbAda), [404]);
rbRefused("direct id: nor delete it", rbSend("DELETE", "api/projects.php?id=9742", $rbAda), [403, 404]);
check("direct id: the project is still there", (bool) one($pdo, "SELECT 1 FROM projects WHERE id = 9742"));
rbRefused("direct id: a member can't delete a project they only belong to", rbSend("DELETE", "api/projects.php?id=9743", $rbAda), [403]);
check("direct id: that project is still there", (bool) one($pdo, "SELECT 1 FROM projects WHERE id = 9743"));
rbRefused("direct id: deleting a client needs clients.delete", rbSend("POST", "api/delete-client.php", $rbAda, ["id" => 9701]), [403]);
check("direct id: the client is still there", (bool) one($pdo, "SELECT 1 FROM users WHERE id = 9701"));

foreach ([["clients.php?id=9702", "client"], ["forms.php?id=9712", "form"], ["responses.php?id=9722", "response"],
          ["content.php?id=9732", "post"], ["projects.php?id=9742", "project"]] as [$path, $what]) {
    refused("direct id v1: another admin's $what is a 404", $path, $rbAda, 404);
}
[, $c] = get("content.php", $rbAda);
check("lists v1: Ada's posts are her own and the one she's on", array_values(array_intersect(ids($c), [9731, 9732, 9733])) === [9731, 9733]
    || array_values(array_intersect(ids($c), [9731, 9732, 9733])) === [9733, 9731], json_encode(ids($c)));
[, $c] = get("projects.php", $rbAda);
$mine = array_values(array_intersect(ids($c), [9741, 9742, 9743]));
sort($mine);
check("lists v1: Ada's projects are her client's and the one she's a member of", $mine === [9741, 9743], json_encode(ids($c)));
[, $c] = get("clients.php", $rbBen);
check("lists v1: a department grants nothing - Ben doesn't see Ada's client", !in_array(9701, ids($c), true) && in_array(9702, ids($c), true), json_encode(ids($c)));
[, $r] = rbSend("GET", "api/admin-responses.php", $rbBen);
$seen = array_map("intval", array_column($r["responses"] ?? [], "id"));
check("lists: the responses page leaves out Ada's", ($r["success"] ?? false) === true && in_array(9722, $seen, true) && !in_array(9721, $seen, true), json_encode($seen));

// ── 3. Files ────────────────────────────────────────────────────────────

require_once $root . "/api/upload-helpers.php";
ensureAttachmentsTable($pdo);
$attachDir = $root . "/uploads/attachments";
if (!is_dir($attachDir)) {
    mkdir($attachDir, 0777, true);
}
$rbFiles = [];
foreach ([["content", 9731, "ada-post"], ["content", 9732, "ben-post"], ["task", 97421, "ben-task"]] as $i => [$type, $ownerId, $name]) {
    $rel = "uploads/attachments/rbac-$name.txt";
    file_put_contents("$root/$rel", "secret $name");
    $rbFiles[] = "$root/$rel";
    seed($pdo, "attachments", [["id" => 9751 + $i, "owner_type" => $type, "owner_id" => $ownerId, "path" => $rel,
        "original_name" => "$name.txt", "ext" => "txt", "size" => 10]]);
}
seed($pdo, "project_tasks", [["id" => 97421, "project_id" => 9742, "title" => "Ben's phase", "status" => "published", "created_by_admin_id" => 9602]]);

[$status, , $raw] = rbSend("GET", "api/attachment.php?id=9751", $rbAda);
check("files: a file on their own post downloads", $status === 200 && $raw === "secret ada-post", "HTTP $status");
foreach ([[9752, "another admin's post"], [9753, "a phase of a project outside theirs"]] as [$id, $what]) {
    [$status, , $raw] = rbSend("GET", "api/attachment.php?id=$id", $rbAda);
    check("files: an attachment on $what is a 404", $status === 404 && strpos($raw, "secret") === false, "HTTP $status");
}
[$status, , $raw] = rbSend("GET", "api/attachment.php?id=9752", $rbBen);
check("files: and its owner gets it", $status === 200 && $raw === "secret ben-post", "HTTP $status");
[$status, , $raw] = rbSend("GET", "api/attachment.php?id=9751", $rbClient);
check("files: a client can't fetch a draft post's file", $status === 404 && strpos($raw, "secret") === false, "HTTP $status");
[$status] = rbSend("GET", "api/attachment.php?id=9751", "");
check("files: no session is a 401", $status === 401, "HTTP $status");

$tmp = sys_get_temp_dir() . "/rbac-upload-" . getmypid() . ".txt";
file_put_contents($tmp, "secret upload");
$rbFiles[] = $tmp;
seed($pdo, "survey_uploaded_files", [["id" => 9761, "response_id" => 9722, "question_id" => 1, "user_id" => 9702,
    "original_name" => "b.txt", "stored_name" => "b", "file_path" => $tmp, "file_type" => "text/plain"]]);
[$status, , $raw] = rbSend("GET", "api/download-survey-file.php?id=9761&token=$rbAda", "");
check("files: a form upload from another admin's client doesn't download", strpos($raw, "secret") === false, "HTTP $status");
check("files: and says not found with a 404", $status === 404, "HTTP $status");
[$status, , $raw] = rbSend("GET", "api/download-survey-file.php?id=9761&token=$rbBen", "");
check("files: their own client's upload does", $status === 200 && $raw === "secret upload", "HTTP $status");
[, , $raw] = rbSend("GET", "api/download-survey-file.php?id=9761&token=$rbClient", "");
check("files: a client only gets their own uploads", strpos($raw, "secret") === false);

$cols = $pdo->query("SHOW COLUMNS FROM source_files")->fetchAll(PDO::FETCH_COLUMN);
foreach (["admin_id" => "INT NULL", "folder_id" => "INT NULL", "stored_name" => "VARCHAR(255) NULL", "file_type" => "VARCHAR(100) NULL"] as $col => $type) {
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE source_files ADD COLUMN $col $type");
    }
}
$tmp2 = sys_get_temp_dir() . "/rbac-source-" . getmypid() . ".txt";
file_put_contents($tmp2, "secret source");
$rbFiles[] = $tmp2;
seed($pdo, "source_files", [["id" => 9771, "admin_id" => 9602, "original_name" => "ben.txt", "file_path" => $tmp2, "file_type" => "text/plain"]]);
[$status, , $raw] = rbSend("GET", "api/download-source-file.php?id=9771&token=$rbAda", "");
check("files: another admin's Files item doesn't download", strpos($raw, "secret") === false, "HTTP $status");
check("files: and says not found with a 404", $status === 404, "HTTP $status");
[$status, , $raw] = rbSend("GET", "api/download-source-file.php?id=9771&token=$rbBen", "");
check("files: their own does", $status === 200 && $raw === "secret source", "HTTP $status");

foreach ($rbFiles as $f) {
    @unlink($f);
}

// ── 4. AI connections follow their person ───────────────────────────────

[$status, $me] = get("me.php", $rbMcp, $viaMcp);
check("ai: a connection gets its person's scopes, not what it was approved with",
    $status === 200 && ($me["data"]["scopes"] ?? null) === scopesAllowedFor($pdo, loadPrincipal($pdo, "admin", 9601)), "HTTP $status " . json_encode($me["data"]["scopes"] ?? null));
check("ai: an Admin's connection can't write content or reach settings",
    !array_intersect(["content:write", "settings:read", "admins:write"], $me["data"]["scopes"] ?? []), json_encode($me["data"]["scopes"] ?? null));
[$status] = get("content.php?id=9732", $rbMcp, $viaMcp);
check("ai: record scope is the browser's (another admin's post is a 404)", $status === 404, "HTTP $status");
[$status, $b] = get("content.php?id=9731", $rbMcp, $viaMcp);
check("ai: and their own opens", $status === 200, "HTTP $status " . json_encode($b));
[$status] = get("permissions.php", $rbMcp, $viaMcp);
check("ai: permissions are out of reach", $status === 403, "HTTP $status");
[$status, $r] = post("api/v1/changes.php", $rbMcp, ["action" => "prepare", "tool" => "set_admin_permissions",
    "args" => ["admin" => 9601, "changes" => ["clients.view_all" => true]]], $viaMcp);
check("ai: nor can it change its own person's permissions", $status === 403 && !overrideRow($pdo, 9601, "clients.view_all"), "HTTP $status");

// The Director takes a page away in Settings: the next AI request follows.
[$status] = post("api/permissions-admin.php", $rbOwner, ["admin_id" => 9601, "changes" => ["pages.content" => false]]);
[, $me] = get("me.php", $rbMcp, $viaMcp);
[$after] = get("content.php?id=9731", $rbMcp, $viaMcp);
check("ai: a page switched off leaves the connection at once", $status === 200 && !in_array("content:read", $me["data"]["scopes"] ?? [], true) && $after === 403,
    "save HTTP $status, then $after " . json_encode($me["data"]["scopes"] ?? null));
// ...and a key given to them arrives the same way.
post("api/permissions-admin.php", $rbOwner, ["admin_id" => 9601, "changes" => ["pages.content" => "default", "content.create" => true]]);
[, $me] = get("me.php", $rbMcp, $viaMcp);
check("ai: a key given in Settings reaches the connection", in_array("content:write", $me["data"]["scopes"] ?? [], true), json_encode($me["data"]["scopes"] ?? null));
post("api/permissions-admin.php", $rbOwner, ["admin_id" => 9601, "changes" => ["content.create" => "default"]]);

// ── 5. Privilege escalation ─────────────────────────────────────────────

[, $r] = rbSend("POST", "api/admin-role.php", $rbOwner, ["admin_id" => 9609, "role" => "owner"]);
check("escalation: nobody is made Director, not even by the Director", one($pdo, "SELECT role FROM admins WHERE id = 9609")["role"] === "seo_admin"
    && ($r["success"] ?? null) !== true, json_encode($r));
rbSend("POST", "api/add-user.php", $rbOwner, ["name" => "New Boss", "email" => "boss@rb.test", "password" => "Passw0rd!long", "role" => "owner"]);
check("escalation: a new admin can't be created as Director", !one($pdo, "SELECT 1 FROM admins WHERE email = 'boss@rb.test' AND role = 'owner'")
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM admins WHERE role = 'owner'")["n"] === 1);
rbSend("POST", "api/update-my-profile.php", $rbAda, ["name" => "Ada", "position" => "", "role" => "owner", "permissions" => ["settings.manage"]]);
forgetPermissions();
check("escalation: a role or permissions sent with your own profile are ignored",
    one($pdo, "SELECT role FROM admins WHERE id = 9601")["role"] === "seo_admin" && !can(rbWho(9601), "settings.manage", $pdo));
rbRefused("escalation: a delegate can't change their own role", rbSend("POST", "api/admin-role.php", $rbDel, ["admin_id" => 9605, "role" => "account_manager"]), [200, 403]);
check("escalation: Del is still a Super Admin", one($pdo, "SELECT role FROM admins WHERE id = 9605")["role"] === "super_admin");
rbRefused("escalation: a delegate can't act on another holder of a Director-only key",
    rbSend("POST", "api/admin-role.php", $rbDel, ["admin_id" => 9608, "role" => "super_admin"]), [200, 403]);
rbRefused("escalation: a manager can't change their report's role", rbSend("POST", "api/admin-role.php", "tok-rb-xan", ["admin_id" => 9609, "role" => "super_admin"]), [403]);
rbRefused("escalation: nor re-arrange the team", rbSend("POST", "api/admin-manager-assignments.php", "tok-rb-xan", ["managerId" => 9606, "adminIds" => [9609, 9601]]), [403]);
rbRefused("escalation: nor give their report a permission", rbSend("POST", "api/permissions-admin.php", "tok-rb-xan", ["admin_id" => 9609, "changes" => ["clients.edit" => true]]), [403]);
rbRefused("escalation: nor themself one", rbSend("POST", "api/permissions-admin.php", "tok-rb-xan", ["admin_id" => 9606, "changes" => ["clients.view_all" => true]]), [403]);
rbRefused("escalation: a role row for the Director doesn't exist", rbSend("POST", "api/permissions-admin.php", $rbOwner, ["role" => "owner", "changes" => ["clients.edit" => false]]), [403, 422]);
check("escalation: and nothing was stored for it", !one($pdo, "SELECT 1 FROM role_permissions WHERE role = 'owner'"));

// A role change ends the session and clears the old role's overrides.
seed($pdo, "admin_permission_overrides", [["admin_id" => 9609, "permission" => "clients.edit", "allowed" => 1]]);
[, $r] = rbSend("POST", "api/admin-role.php", $rbOwner, ["admin_id" => 9609, "role" => "super_admin"]);
check("role change: saved", one($pdo, "SELECT role FROM admins WHERE id = 9609")["role"] === "super_admin", json_encode($r));
check("role change: the old overrides are gone", !overrideRow($pdo, 9609, "clients.edit"));
[$status] = rbSend("GET", "api/admin-users.php", "tok-rb-sid");
check("role change: the old session is a 401", $status === 401, "HTTP $status");
$pdo->exec("UPDATE admins SET role = 'seo_admin', managed_by_admin_id = 9606 WHERE id = 9609");

// ── 6. Bulk saves and the hierarchy ─────────────────────────────────────

/** Who sits under $managerId, lowest id first. */
function reportsOf(PDO $pdo, $managerId)
{
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE managed_by_admin_id = ? ORDER BY id");
    $stmt->execute([(int) $managerId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// A delegate's full replace of Xan's team must not carry people they may not touch.
[, $r] = rbSend("POST", "api/admin-manager-assignments.php", $rbDel, ["managerId" => 9606, "adminIds" => []]);
$left = reportsOf($pdo, 9606);
check("bulk: a delegate emptying a team leaves themself where they were", in_array(9605, $left, true), json_encode([$r, $left]));
check("bulk: and leaves a holder of a Director-only key where they were", in_array(9608, $left, true), json_encode($left));
check("bulk: but moves the people they may move", !in_array(9609, $left, true), json_encode($left));

// The Director's bulk save: only ids the manager may take survive.
[, $r] = rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9603, "adminIds" => [9606, 1, 9603, 9607, 9609, 99999]]);
check("bulk: only valid reports are kept (no Account Manager, Director, self or unknown id)", reportsOf($pdo, 9603) === [9607, 9609], json_encode([$r, reportsOf($pdo, 9603)]));
[, $r] = rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9607, "adminIds" => [9605, 9601]]);
check("bulk: a Super Admin manager takes Admins only", reportsOf($pdo, 9607) === [9601], json_encode([$r, reportsOf($pdo, 9607)]));
rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9607, "adminIds" => []]);
rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9603, "adminIds" => []]);
rbRefused("bulk: an Admin can't be made a manager", rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9601, "adminIds" => [9602]]), [200, 404]);
check("bulk: and nobody ended up under them", reportsOf($pdo, 9601) === []);

/** Admins whose chain of managers comes back to themself. */
function managerCycles(PDO $pdo)
{
    $up = [];
    foreach ($pdo->query("SELECT id, managed_by_admin_id FROM admins")->fetchAll() as $row) {
        $up[(int) $row["id"]] = $row["managed_by_admin_id"] === null ? null : (int) $row["managed_by_admin_id"];
    }
    $loops = [];
    foreach (array_keys($up) as $start) {
        $seen = [];
        for ($at = $up[$start]; $at !== null && isset($up[$at]); $at = $up[$at]) {
            if ($at === $start) {
                $loops[] = $start;
                break;
            }
            if (isset($seen[$at])) {
                break;
            }
            $seen[$at] = true;
        }
    }
    return $loops;
}

/** Links that break MANAGER_CAN_MANAGE: [admin id => manager id]. */
function invalidManagerLinks(PDO $pdo)
{
    $bad = [];
    foreach ($pdo->query("SELECT a.id, a.role, m.id AS mid, m.role AS mrole FROM admins a JOIN admins m ON m.id = a.managed_by_admin_id")->fetchAll() as $row) {
        if (!in_array($row["role"], MANAGER_CAN_MANAGE[$row["mrole"]] ?? [], true)) {
            $bad[(int) $row["id"]] = (int) $row["mid"];
        }
    }
    return $bad;
}

check("hierarchy: no cycles to start with", managerCycles($pdo) === [], json_encode(managerCycles($pdo)));

// Role changes on both ends of a link, then a link back the other way.
rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9606, "adminIds" => [9607]]);
check("hierarchy: Yu (Super Admin) under Xan (Account Manager)", in_array(9607, reportsOf($pdo, 9606), true));
rbSend("POST", "api/admin-role.php", $rbOwner, ["admin_id" => 9607, "role" => "account_manager"]);
check("hierarchy: a role change drops a link the new role can't have", !array_key_exists(9607, invalidManagerLinks($pdo)), json_encode(invalidManagerLinks($pdo)));
rbSend("POST", "api/admin-role.php", $rbOwner, ["admin_id" => 9606, "role" => "super_admin"]);
rbSend("POST", "api/admin-manager-assignments.php", $rbOwner, ["managerId" => 9607, "adminIds" => [9606]]);
check("hierarchy: role changes can't be used to build a loop", managerCycles($pdo) === [], json_encode(managerCycles($pdo)));
check("hierarchy: every remaining link follows the rules", array_intersect_key(invalidManagerLinks($pdo), array_flip(range(9601, 9610))) === [],
    json_encode(invalidManagerLinks($pdo)));
$pdo->exec("UPDATE admins SET role = 'account_manager', managed_by_admin_id = NULL WHERE id = 9606");
$pdo->exec("UPDATE admins SET role = 'super_admin', managed_by_admin_id = NULL WHERE id = 9607");
