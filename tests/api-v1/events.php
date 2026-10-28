<?php

/*
| The event log - required by run.php last, sharing its scope, so the
| changes every earlier file made are already in it.
|
|   1. Changes land in admin_events with the right actor, source and
|      summary: browser writes as 'web', MCP writes as 'mcp', and a
|      rolled-back change leaves nothing behind.
|   2. Reading it is the Director's alone, in the browser (admin-events.php)
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
[$status, $r] = get("events.php", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "list_admin_events"]);
check("events: an owner connection without events:read", $status === 403 && $r["error"]["code"] === "insufficient_scope", "HTTP $status " . json_encode($r));
$pdo->prepare("UPDATE mcp_grants SET scopes = 'settings:read events:read' WHERE id = 9")->execute();
[$status, $r] = get("events.php?limit=10", $ownerMcp, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "list_admin_events"]);
check("events: an owner connection with it", $status === 200 && count($r["data"]["items"]) === 10, "HTTP $status " . json_encode($r));
$fixtures["list_admin_events"] = $r["data"] ?? null;

// ── 3. Retention ─────────────────────────────────────────────────────────

$pdo->prepare("
    INSERT INTO admin_events (actor_admin_id, actor_name, actor_role, action, target_type, summary, created_at)
    VALUES (1, 'Olivia', 'owner', 'settings.changed', 'settings', 'Long ago', NOW() - INTERVAL 13 MONTH),
           (1, 'Olivia', 'owner', 'settings.changed', 'settings', 'Not so long ago', NOW() - INTERVAL 11 MONTH)
")->execute();
pageGet("api/admin-events.php?action=list", $owner);
check("events: older than 12 months is pruned on read", !eventRow($pdo, "summary = 'Long ago'")
    && eventRow($pdo, "summary = 'Not so long ago'"));
