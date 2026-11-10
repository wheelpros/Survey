<?php

/*
|--------------------------------------------------------------------------
| Connecting an AI assistant: sign-in, what's offered, what replaces what
|--------------------------------------------------------------------------
|
| Loaded by run.php. api/sign-in.php, api/oauth/consent.php and grants.php, and
| the storage steps the MCP server drives in api/oauth/server.php.
*/

/** GET any portal path, not just api/v1. */
function getPath($path, $token)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true, "timeout" => 10],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
}

function oauthStore($action, array $body)
{
    global $mcpKey;
    return post("api/oauth/server.php", "", ["action" => $action] + $body, ["X-MCP-Key" => $mcpKey]);
}

function registerApp($clientId, $name, array $redirects)
{
    [$status] = oauthStore("register_client", ["client" => [
        "client_id" => $clientId, "client_name" => $name, "redirect_uris" => $redirects,
    ]]);
    check("registered $clientId", $status === 201, "HTTP $status");
}

function requestAccess($clientId, $redirect, array $scopes)
{
    [, $r] = oauthStore("create_request", [
        "client_id" => $clientId, "redirect_uri" => $redirect, "code_challenge" => str_repeat("c", 43),
        "scopes" => $scopes, "state" => "s", "resource" => "https://mcp.test/mcp",
    ]);
    return $r["request_id"] ?? "";
}

/** Goes all the way through: asks, approves as $token, exchanges the code. */
function connectApp($clientId, $redirect, array $scopes, $token)
{
    $id = requestAccess($clientId, $redirect, $scopes);
    [, $approved] = post("api/oauth/consent.php", $token, ["request" => $id, "decision" => "approve"]);
    parse_str((string) parse_url($approved["redirect"] ?? "", PHP_URL_QUERY), $query);
    [$status, $tokens] = oauthStore("exchange", ["client_id" => $clientId, "code" => $query["code"] ?? ""]);
    check("$clientId got its tokens", $status === 200 && !empty($tokens["access_token"]), "HTTP $status " . json_encode($tokens));
    return (string) ($tokens["access_token"] ?? "");
}

function stillWorks($accessToken)
{
    [, $r] = oauthStore("introspect", ["token" => $accessToken]);
    return ($r["active"] ?? null) === true;
}

// ── One sign-in ──────────────────────────────────────────────────────────

$hash = function ($password) {
    return password_hash($password, PASSWORD_BCRYPT, ["cost" => 4]);
};
seed($pdo, "admins", [
    ["id" => 170, "name" => "Sia", "email" => "sia@w.test", "role" => "super_admin", "password" => $hash("sia-pass")],
    ["id" => 171, "name" => "Dual Staff", "email" => "dual@w.test", "role" => "super_admin", "password" => $hash("same-pass")],
    ["id" => 172, "name" => "Dual Other", "email" => "other@w.test", "role" => "super_admin", "password" => $hash("staff-pass")],
    ["id" => 173, "name" => "Off", "email" => "off@w.test", "role" => "super_admin", "password" => $hash("off-pass"), "active" => 0],
    ["id" => 174, "name" => "Off Dual", "email" => "offdual@w.test", "role" => "super_admin", "password" => $hash("off-dual"), "active" => 0],
]);
seed($pdo, "users", [
    ["id" => 170, "name" => "Cal", "email" => "cal@c.test", "approved" => 1, "password" => $hash("cal-pass"), "company_name" => "Cal Co"],
    ["id" => 171, "name" => "Dual Client", "email" => "dual@w.test", "approved" => 1, "password" => $hash("same-pass"), "company_name" => "Dual Co"],
    ["id" => 172, "name" => "Other Client", "email" => "other@w.test", "approved" => 1, "password" => $hash("client-pass")],
    ["id" => 173, "name" => "Waiting", "email" => "waiting@c.test", "approved" => 0, "password" => $hash("wait-pass")],
    ["id" => 174, "name" => "Off Dual Client", "email" => "offdual@w.test", "approved" => 1, "password" => $hash("off-dual")],
]);

$signIn = function (array $body) {
    return post("api/sign-in.php", "", $body);
};

[, $r] = $signIn(["email" => "sia@w.test", "password" => "sia-pass"]);
check("sign-in: staff get a staff session", ($r["success"] ?? null) === true && !empty($r["token"]) && ($r["admin"]["id"] ?? 0) == 170 && !isset($r["user"]), json_encode($r));
[$status] = get("clients.php", (string) ($r["token"] ?? ""));
check("sign-in: that session works", $status === 200, "HTTP $status");

[, $r] = $signIn(["email" => "CAL@c.test", "password" => "cal-pass"]);
check("sign-in: clients get a client session", ($r["success"] ?? null) === true && !empty($r["token"]) && ($r["user"]["id"] ?? 0) == 170 && !isset($r["admin"]), json_encode($r));

[, $wrong] = $signIn(["email" => "sia@w.test", "password" => "nope"]);
[, $nobody] = $signIn(["email" => "nobody@w.test", "password" => "nope"]);
check("sign-in: a wrong password fails", ($wrong["success"] ?? null) === false, json_encode($wrong));
check("sign-in: no account reads exactly like a wrong password", $wrong === $nobody, json_encode([$wrong, $nobody]));
[, $r] = $signIn(["email" => "sia@w.test", "password" => ""]);
check("sign-in: a password is required", ($r["success"] ?? null) === false && empty($r["token"]), json_encode($r));

[, $r] = $signIn(["email" => "off@w.test", "password" => "off-pass"]);
check("sign-in: a deactivated admin is told so", ($r["success"] ?? null) === false && strpos($r["message"] ?? "", "deactivated") !== false, json_encode($r));
[, $r] = $signIn(["email" => "waiting@c.test", "password" => "wait-pass"]);
check("sign-in: an unapproved client is told so", ($r["success"] ?? null) === false && strpos($r["message"] ?? "", "pending") !== false, json_encode($r));

[, $r] = $signIn(["email" => "dual@w.test", "password" => "same-pass"]);
check("sign-in: both accounts match, so it asks and gives no session yet", array_column($r["choose"] ?? [], "account") === ["admin", "user"] && empty($r["token"]), json_encode($r));
[, $r] = $signIn(["email" => "dual@w.test", "password" => "same-pass", "account" => "user"]);
check("sign-in: choosing the client account", ($r["user"]["id"] ?? 0) == 171 && !empty($r["token"]), json_encode($r));
[, $r] = $signIn(["email" => "dual@w.test", "password" => "same-pass", "account" => "admin"]);
check("sign-in: choosing the staff account", ($r["admin"]["id"] ?? 0) == 171 && !empty($r["token"]), json_encode($r));

[, $r] = $signIn(["email" => "other@w.test", "password" => "client-pass"]);
check("sign-in: same email, the password picks the account", ($r["user"]["id"] ?? 0) == 172 && empty($r["choose"]), json_encode($r));
[, $r] = $signIn(["email" => "other@w.test", "password" => "client-pass", "account" => "admin"]);
check("sign-in: naming an account the password doesn't open fails", ($r["success"] ?? null) === false && empty($r["token"]), json_encode($r));
[, $r] = $signIn(["email" => "offdual@w.test", "password" => "off-dual"]);
check("sign-in: a deactivated account isn't offered as a choice", ($r["user"]["id"] ?? 0) == 174 && empty($r["choose"]), json_encode($r));

// ── What the approval page is told ───────────────────────────────────────

registerApp("short-list-app", "Short List", ["https://short.test/cb"]);
$id = requestAccess("short-list-app", "https://short.test/cb", ["clients:read", "admins:write", "messages:write"]);
[$status, $r] = getPath("api/oauth/consent.php?request=$id", $owner);
// Whatever the app asks for, the page offers what the person may do - the
// connection follows their permissions from then on.
check("consent: everything the role allows, whatever was asked for", $status === 200 && count($r["scopes"] ?? []) === 25
    && !array_key_exists("not_requested", $r), json_encode($r));
$byScope = array_column($r["scopes"] ?? [], null, "scope");
check("consent: areas and access", ($byScope["clients:read"]["area"] ?? "") === "Clients" && ($byScope["clients:read"]["access"] ?? "") === "view"
    && ($byScope["admins:write"]["area"] ?? "") === "Admin team" && ($byScope["admins:write"]["access"] ?? "") === "change", json_encode($byScope));
check("consent: what acts in your name is flagged", ($byScope["admins:write"]["sensitive"] ?? null) === true
    && ($byScope["messages:write"]["sensitive"] ?? null) === true && ($byScope["clients:read"]["sensitive"] ?? null) === false, json_encode($byScope));
$id = requestAccess("short-list-app", "https://short.test/cb", ["clients:read"]);
[, $r] = getPath("api/oauth/consent.php?request=$id", "tok-seo");
$seoScopes = array_column($r["scopes"] ?? [], "scope");
check("consent: never more than the role allows", in_array("clients:read", $seoScopes, true)
    && !in_array("settings:read", $seoScopes, true) && !in_array("admins:write", $seoScopes, true), json_encode($seoScopes));

[$status] = getPath("api/oauth/consent.php?request=$id", "");
check("consent: needs a session", $status === 401, "HTTP $status");

// ── What Connected apps reports ──────────────────────────────────────────

$shortToken = connectApp("short-list-app", "https://short.test/cb", ["clients:read", "admins:write"], $owner);
[, $r] = getPath("api/oauth/grants.php", $owner);
$short = array_values(array_filter($r["grants"] ?? [], function ($g) { return $g["client_name"] === "Short List"; }))[0] ?? [];
// Approved with two scopes, it can still do everything the Director may.
check("grants: what it can do is what its person may do", count($short["scopes"] ?? []) === 25
    && in_array("events:read", array_column($short["scopes"], "scope"), true) && !array_key_exists("missing", $short), json_encode($short));
check("grants: described", ($short["scopes"][0]["description"] ?? "") !== "" && ($short["scopes"][0]["area"] ?? "") !== "", json_encode($short));

[, $r] = getPath("api/oauth/grants.php", "tok-seo");
check("grants: only your own", ($r["grants"] ?? null) === [], json_encode($r));

// ── Reconnecting replaces ────────────────────────────────────────────────

registerApp("assistant-first", "Assistant", ["https://assistant.test/cb"]);
registerApp("assistant-again", "assistant", ["https://assistant.test/cb2"]);
registerApp("copycat-app", "Assistant", ["https://copycat.test/cb", "https://assistant.test/cb"]);
registerApp("other-app", "Something else", ["https://assistant.test/cb"]);

$first = connectApp("assistant-first", "https://assistant.test/cb", ["clients:read"], $am);
check("replace: the first connection works", stillWorks($first));

$other = connectApp("other-app", "https://assistant.test/cb", ["clients:read"], $am);
check("replace: the same host under another name is another app", stillWorks($first) && stillWorks($other));

$copycat = connectApp("copycat-app", "https://copycat.test/cb", ["clients:read"], $am);
check("replace: the same name sending the code elsewhere ends nothing", stillWorks($first) && stillWorks($copycat));

$again = connectApp("assistant-again", "https://assistant.test/cb2", ["clients:read", "forms:read"], $am);
check("replace: reconnecting ends the old connection", !stillWorks($first), "old token still active");
check("replace: and the new one works", stillWorks($again));
check("replace: other apps keep theirs", stillWorks($other));
// Its name matches and it lists the real host, so it counts as the same app. It
// can only do that to itself: the check above shows it can't end the real one.
check("replace: claiming to be the app ends your own connection", !stillWorks($copycat));

$elsewhere = connectApp("assistant-again", "https://assistant.test/cb2", ["clients:read"], $seo);
$repeat = connectApp("assistant-again", "https://assistant.test/cb2", ["clients:read"], $am);
check("replace: the same registration reconnecting ends its old connection", !stillWorks($again) && stillWorks($repeat));
check("replace: only ever this person's", stillWorks($elsewhere));

[, $r] = oauthStore("register_client", ["client" => ["client_id" => "local-tool-one", "client_name" => "Local tool", "redirect_uris" => ["http://localhost:3000/cb"]]]);
[, $r] = oauthStore("register_client", ["client" => ["client_id" => "local-tool-two", "client_name" => "Local tool", "redirect_uris" => ["http://localhost:3000/cb"]]]);
$laptop = connectApp("local-tool-one", "http://localhost:3000/cb", ["clients:read"], $am);
$desktop = connectApp("local-tool-two", "http://localhost:3000/cb", ["clients:read"], $am);
check("replace: two copies of a local tool are two connections", stillWorks($laptop) && stillWorks($desktop));

$id = requestAccess("short-list-app", "https://short.test/cb", ["clients:read"]);
post("api/oauth/consent.php", $owner, ["request" => $id, "decision" => "approve"]);
check("replace: approving alone ends nothing, until the tokens are issued", stillWorks($shortToken));

// ── Too many attempts ────────────────────────────────────────────────────
// Last: this fills the bucket for the rest of the window.

$last = 0;
for ($i = 0; $i < 25 && $last !== 429; $i++) {
    [$last] = $signIn(["email" => "nobody@w.test", "password" => "guess"]);
}
check("sign-in: repeated attempts are refused", $last === 429, "HTTP $last after $i attempts");
