<?php

/*
| The event log - required by run.php last, sharing its scope, so the
| changes every earlier file made are already in it.
|
|   1. Changes land in admin_events with the right actor, source and
|      summary: browser writes as 'web', MCP writes as 'mcp', and a
|      rolled-back change leaves nothing behind.
|   2. Reading it needs events.view - the Director's by default - in the browser (admin-events.php)
|      and through api/v1 (events.php, scope events:read), with the filters
|      and paging both pages offer.
|   3. Rows older than 12 months go when the log is read.
*/

function eventRow(PDO $pdo, $where, array $params = [])
{
    return one($pdo, "SELECT * FROM admin_events WHERE $where ORDER BY id DESC LIMIT 1", $params);
}

// ── 1. Writing ───────────────────────────────────────────────────────────

$e = eventRow($pdo, "action = 'form.created' AND target_label = 'Via MCP'");
check("events: an MCP write is logged as mcp", $e && $e["via"] === "mcp" && (int) $e["actor_admin_id"] === 2
    && $e["actor_name"] === "Sam" && $e["actor_role"] === "super_admin" && (int) $e["client_id"] === 10, json_encode($e));

$e = eventRow($pdo, "action = 'admin.role_changed'");
check("events: a role change says from and to", $e && $e["via"] === "web" && (int) $e["actor_admin_id"] === 1
    && strpos($e["summary"], " role from ") !== false, json_encode($e));

$before = (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events")["n"];
refusedChange("events: a refused change", $am, "change_admin_role", ["admin" => 7, "role" => "super_admin"], 403);
check("events: a refused change logs nothing", (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events")["n"] === $before);

// A client acting on their own account is not an admin action.
$clientRows = (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE actor_role = 'client'")["n"];
check("events: client actions are not logged", $clientRows === 0);

// Approving a sign-up through the browser endpoint, which writes inline.
[, $r] = post("api/admin-users.php", $owner, ["action" => "approve", "userId" => 12]);
$e = eventRow($pdo, "action = 'client.approved' AND target_id = 12");
check("events: an inline browser write is logged", ($r["success"] ?? false) === true && $e
    && $e["summary"] === "Approved Gina's account" && (int) $e["client_id"] === 12, json_encode([$r, $e]));

// ── 2. Reading, in the browser ───────────────────────────────────────────

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [$status] = pageGet("api/admin-events.php?action=list", $token);
    check("events page: a $who is refused", $status === 403, "HTTP $status");
}
[$status] = pageGet("api/admin-events.php?action=list", "nobody");
check("events page: no session is refused", $status === 401, "HTTP $status");

[$status, $r] = pageGet("api/admin-events.php?action=list&limit=5", $owner);
$total = (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events")["n"];
check("events page: the Director reads it, newest first", $status === 200 && count($r["items"]) === 5
    && $r["total"] === $total && $r["total_pages"] === (int) ceil($total / 5)
    && $r["items"][0]["id"] > $r["items"][4]["id"] && is_int($r["items"][0]["created_ts"]), "HTTP $status " . json_encode($r));

[, $r2] = pageGet("api/admin-events.php?action=list&limit=5&page=2", $owner);
check("events page: page 2 carries on", ($r2["items"][0]["id"] ?? 0) < $r["items"][4]["id"], json_encode($r2));

[, $r] = pageGet("api/admin-events.php?action=list&area=admin&limit=100", $owner);
check("events page: the area filter", $r["items"] && !array_filter($r["items"], function ($i) {
    return $i["area"] !== "admin";
}), json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=list&actor_id=2&limit=100", $owner);
check("events page: the admin filter", $r["items"] && !array_filter($r["items"], function ($i) {
    return $i["actor_id"] !== 2;
}), json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=list&client_id=12", $owner);
check("events page: the client filter", count($r["items"]) === 1 && $r["items"][0]["action"] === "client.approved", json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=list&q=" . urlencode("Via MCP"), $owner);
check("events page: the search", count($r["items"]) >= 1 && strpos($r["items"][0]["summary"], "Via MCP") !== false, json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=list&from=" . day("+1"), $owner);
check("events page: the date filter", $r["total"] === 0, json_encode($r));

[$status] = pageGet("api/admin-events.php?action=list&from=yesterday", $owner);
check("events page: a bad date is refused", $status === 400, "HTTP $status");

[$status, $r] = pageGet("api/admin-events.php?action=filters", $owner);
check("events page: the filter lists", $status === 200 && in_array("Sam", array_column($r["admins"], "name"), true)
    && in_array("project", array_column($r["areas"], "key"), true) && in_array("Acme Ltd", array_column($r["clients"], "name"), true),
    json_encode($r));

// ── 2. Reading, through api/v1 ───────────────────────────────────────────

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    refused("events: a $who can't read them", "events.php", $token, 403);
}

[$status, $r] = get("events.php?limit=3", $owner);
check("events: the Director reads them, paged", $status === 200 && count($r["data"]["items"]) === 3
    && $r["data"]["next_cursor"] !== null, "HTTP $status " . json_encode($r));
$first = $r["data"]["items"][0] ?? [];
check("events: what people typed is untrusted", isset($first["untrusted_content"]["summary"])
    && !array_key_exists("summary", $first) && !array_key_exists("ip", $first), json_encode($first));
[, $next] = get("events.php?limit=3&cursor=" . urlencode($r["data"]["next_cursor"]), $owner);
check("events: the cursor carries on", ($next["data"]["items"][0]["id"] ?? 0) < $r["data"]["items"][2]["id"], json_encode($next));

[$status, $r] = get("events.php?area=form&q=" . urlencode("Via MCP"), $owner);
check("events: filtered", $status === 200 && count($r["data"]["items"]) === 1
    && $r["data"]["items"][0]["via"] === "mcp", "HTTP $status " . json_encode($r));
[$status] = get("events.php?area=nonsense", $owner);
check("events: an unknown area is refused", $status === 422, "HTTP $status");

// An owner's MCP connection needs the scope; without it, a 403.
$ownerMcp = "wzat_" . str_repeat("e", 43);
seed($pdo, "mcp_grants", [["id" => 9, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 1,
    "scopes" => "settings:read", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($ownerMcp), "kind" => "access", "grant_id" => 9, "expires_at" => time() + 3600]]);
[$status, $r] = get("events.php", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "get_tracking"]);
check("events: an owner connection without events:read", $status === 403 && $r["error"]["code"] === "insufficient_scope", "HTTP $status " . json_encode($r));
$pdo->prepare("UPDATE mcp_grants SET scopes = 'settings:read events:read' WHERE id = 9")->execute();
[$status, $r] = get("events.php?limit=10", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "get_tracking"]);
check("events: an owner connection with it", $status === 200 && count($r["data"]["items"]) === 10, "HTTP $status " . json_encode($r));
$fixtures["get_tracking"] = $r["data"] ?? null;

// ── AI activity: the second tab, under the same permission ───────────────

$pdo->exec("DELETE FROM mcp_audit_log");
seed($pdo, "oauth_clients", [["client_id" => "c-chat", "client_name" => "Chat <b>App</b>", "metadata" => "{}"]]);
seed($pdo, "mcp_audit_log", [
    ["id" => 9001, "grant_id" => 1, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 2, "tool" => "list_forms",
     "endpoint" => "forms.php", "method" => "GET", "status" => 200, "created_at" => "2026-01-10 12:00:00"],
    ["id" => 9002, "grant_id" => 1, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 2, "tool" => "get_settings",
     "endpoint" => "settings.php", "method" => "GET", "status" => 403, "created_at" => "2026-01-11 12:00:00"],
    ["id" => 9003, "grant_id" => 2, "client_id" => "c-chat", "principal_kind" => "user", "principal_id" => 10, "tool" => "my_overview",
     "endpoint" => "me.php", "method" => "GET", "status" => 200, "created_at" => "2026-01-12 12:00:00"],
    ["id" => 9004, "grant_id" => 3, "client_id" => "gone", "principal_kind" => "admin", "principal_id" => 999, "tool" => null,
     "endpoint" => "clients.php", "method" => "GET", "status" => 200, "created_at" => "2026-01-13 12:00:00"],
]);

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [$status] = pageGet("api/admin-events.php?action=ai_activity", $token);
    check("ai activity page: a $who is refused", $status === 403, "HTTP $status");
    [$status] = pageGet("api/admin-events.php?action=ai_filters", $token);
    check("ai activity filters: a $who is refused", $status === 403, "HTTP $status");
}
[$status] = pageGet("api/admin-events.php?action=ai_activity", "nobody");
check("ai activity page: no session is refused", $status === 401, "HTTP $status");

[$status, $r] = pageGet("api/admin-events.php?action=ai_activity&limit=3", $owner);
$row = $r["items"][0] ?? [];
check("ai activity page: the Director reads it, newest first and paged", $status === 200 && count($r["items"]) === 3
    && $r["total"] === 4 && $r["total_pages"] === 2 && $row["id"] === 9004 && $row["person"] === "(deleted)"
    && $row["app"] === "AI assistant" && $row["tool"] === null
    && $row["created_ts"] === strtotime("2026-01-13 12:00:00 UTC"), "HTTP $status " . json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&limit=3&page=2", $owner);
check("ai activity page: page 2 carries on", ($r["page"] ?? 0) === 2 && array_column($r["items"], "id") === [9001], json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&page=99", $owner);
check("ai activity page: a page past the end is the last one", ($r["page"] ?? 0) === 1 && count($r["items"]) === 4, json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&who=admin:2", $owner);
check("ai activity page: the who filter", array_column($r["items"], "id") === [9002, 9001], json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&who=user:10", $owner);
check("ai activity page: clients are marked", array_column($r["items"], "id") === [9003]
    && $r["items"][0]["person_kind"] === "client" && $r["items"][0]["person"] === "Alice", json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&app=c-chat", $owner);
check("ai activity page: the app filter", array_column($r["items"], "id") === [9003] && $r["items"][0]["app"] === "Chat <b>App</b>", json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&result=refused", $owner);
check("ai activity page: the result filter", array_column($r["items"], "id") === [9002], json_encode($r));

[, $r] = pageGet("api/admin-events.php?action=ai_activity&from=2026-01-11&to=2026-01-12", $owner);
check("ai activity page: the date filter", array_column($r["items"], "id") === [9003, 9002], json_encode($r));

[$status] = pageGet("api/admin-events.php?action=ai_activity&from=yesterday", $owner);
check("ai activity page: a bad date is refused", $status === 400, "HTTP $status");

[$status, $r] = pageGet("api/admin-events.php?action=ai_filters", $owner);
check("ai activity filters: everyone in the log", $status === 200 && in_array("admin:2", array_column($r["people"], "value"), true)
    && in_array("user:10", array_column($r["people"], "value"), true) && in_array("admin:999", array_column($r["people"], "value"), true)
    && in_array("c-chat", array_column($r["apps"], "value"), true), json_encode($r));

// Someone given Tracking sees the tab too.
$pdo->prepare("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (2, 'events.view', 1)")->execute();
[$status] = pageGet("api/admin-events.php?action=ai_activity", $sa);
check("ai activity page: whoever holds events.view reads it", $status === 200, "HTTP $status");
$pdo->prepare("DELETE FROM admin_permission_overrides WHERE admin_id = 2 AND permission = 'events.view'")->execute();

// Through api/v1.
foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    refused("ai activity: a $who can't read it", "ai-activity.php", $token, 403);
}
[$status, $r] = get("ai-activity.php?limit=2", $owner);
$first = $r["data"]["items"][0] ?? [];
check("ai activity: the Director reads it, paged", $status === 200 && count($r["data"]["items"]) === 2
    && $r["data"]["next_cursor"] !== null && $first["id"] === 9004 && $first["result"] === "ok", "HTTP $status " . json_encode($r));
check("ai activity: names are untrusted", ($first["untrusted_content"]["person_name"] ?? null) === "(deleted)"
    && !array_key_exists("person_name", $first) && !array_key_exists("app", $first), json_encode($first));
[, $next] = get("ai-activity.php?limit=2&cursor=" . urlencode($r["data"]["next_cursor"]), $owner);
check("ai activity: the cursor carries on", array_column($next["data"]["items"] ?? [], "id") === [9002, 9001], json_encode($next));
[, $r] = get("ai-activity.php?person_kind=client&person_id=10", $owner);
check("ai activity: filtered by person", array_column($r["data"]["items"] ?? [], "id") === [9003]
    && $r["data"]["items"][0]["person"] === ["kind" => "client", "id" => 10], json_encode($r));
[, $r] = get("ai-activity.php?result=refused", $owner);
check("ai activity: filtered by result", array_column($r["data"]["items"] ?? [], "id") === [9002], json_encode($r));
[$status] = get("ai-activity.php?result=nonsense", $owner);
check("ai activity: an unknown result is refused", $status === 422, "HTTP $status");

$pdo->prepare("UPDATE mcp_grants SET scopes = 'settings:read' WHERE id = 9")->execute();
[$status, $r] = get("ai-activity.php", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "get_ai_activity"]);
check("ai activity: a connection without events:read", $status === 403 && $r["error"]["code"] === "insufficient_scope", "HTTP $status " . json_encode($r));
$pdo->prepare("UPDATE mcp_grants SET scopes = 'settings:read events:read' WHERE id = 9")->execute();
[$status, $r] = get("ai-activity.php?limit=10", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "get_ai_activity"]);
check("ai activity: a connection with it", $status === 200 && count($r["data"]["items"]) >= 4, "HTTP $status " . json_encode($r));
$fixtures["get_ai_activity"] = $r["data"] ?? null;

// ── The Director's switch: client access ─────────────────────────────────

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [$status] = pageGet("api/oauth/admin.php", $token);
    check("client access: a $who can't read it", $status === 403, "HTTP $status");
    [$status] = post("api/oauth/admin.php", $token, ["clients_enabled" => true]);
    check("client access: a $who can't change it", $status === 403, "HTTP $status");
}
[$status] = pageGet("api/oauth/admin.php", "nobody");
check("client access: no session is refused", $status === 401, "HTTP $status");

// Put back afterwards: the files after this one rely on clients being let in.
$clientAccessBefore = clientsMayUseMcp($pdo);

[$status, $r] = post("api/oauth/admin.php", $owner, ["clients_enabled" => true]);
check("client access: the Director turns it on", $status === 200 && $r["clients_enabled"] === true && clientsMayUseMcp($pdo), "HTTP $status " . json_encode($r));

[$status, $r] = pageGet("api/oauth/admin.php", $owner);
check("client access: reads as on, with no activity in the reply", $status === 200 && $r["clients_enabled"] === true
    && !array_key_exists("activity", $r), "HTTP $status " . json_encode($r));

seed($pdo, "mcp_grants", [
    ["id" => 77, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 11, "scopes" => "self:read", "resource" => "https://mcp.test/mcp"],
    ["id" => 78, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 3, "scopes" => "clients:read", "resource" => "https://mcp.test/mcp"],
]);
[$status, $r] = post("api/oauth/admin.php", $owner, ["clients_enabled" => false]);
$clientGrant = one($pdo, "SELECT revoked_at FROM mcp_grants WHERE id = 77");
$staffGrant = one($pdo, "SELECT revoked_at FROM mcp_grants WHERE id = 78");
check("client access: turning it off ends clients' connections, not staff's", $status === 200 && $r["clients_enabled"] === false
    && $clientGrant["revoked_at"] !== null && $staffGrant["revoked_at"] === null, json_encode([$r, $clientGrant, $staffGrant]));
$pdo->exec("DELETE FROM mcp_grants WHERE id IN (77, 78)");
setSiteSetting($pdo, "mcp_clients_enabled", $clientAccessBefore ? "1" : "0");

// ── 3. Retention ─────────────────────────────────────────────────────────

$pdo->prepare("
    INSERT INTO admin_events (actor_admin_id, actor_name, actor_role, action, target_type, summary, created_at)
    VALUES (1, 'Olivia', 'owner', 'settings.changed', 'settings', 'Long ago', NOW() - INTERVAL 13 MONTH),
           (1, 'Olivia', 'owner', 'settings.changed', 'settings', 'Not so long ago', NOW() - INTERVAL 11 MONTH)
")->execute();
pageGet("api/admin-events.php?action=list", $owner);
check("events: older than 12 months is pruned on read", !eventRow($pdo, "summary = 'Long ago'")
    && eventRow($pdo, "summary = 'Not so long ago'"));
