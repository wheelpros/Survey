<?php

/*
|--------------------------------------------------------------------------
| The person's decision on an AI assistant's request for access
|--------------------------------------------------------------------------
|
| Backs oauth-consent.html. The Node MCP server's /authorize stores the
| request (api/oauth/server.php create_request) and sends the browser to
| oauth-consent.html?request=<id>; that page calls this with the person's
| own portal session.
|
|   GET  ?request=<id>                      -> what is being asked, of whom
|   POST { request, decision: "approve" | "deny" }
|                                           -> { redirect } back to the app
|
| Browser sessions only. An MCP access token is refused outright: an AI
| must never be able to approve its own access.
*/

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth.php";

ensureOAuthTables($pdo);

$p = currentPrincipal($pdo);
if (!$p || $p["via"] !== "session") {
    oauthReply(401, ["success" => false, "message" => "Please sign in to continue"]);
}

$method = $_SERVER["REQUEST_METHOD"];
$in = $method === "POST" ? oauthJsonBody() : $_GET;
$requestId = (string) ($in["request"] ?? "");

if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $requestId)) {
    oauthReply(400, ["success" => false, "message" => "This sign-in link is not valid"]);
}

$stmt = $pdo->prepare("
    SELECT r.*, c.client_name, c.metadata
    FROM oauth_requests r
    JOIN oauth_clients c ON c.client_id = r.client_id
    WHERE r.id = ? AND r.expires_at > ?
    LIMIT 1
");
$stmt->execute([$requestId, time()]);
$request = $stmt->fetch();

if (!$request) {
    oauthReply(404, ["success" => false, "message" => "This request has expired. Start connecting again from your AI assistant."]);
}

// What may be granted: what was asked for, within what this person's role
// allows - or, if the app asked for nothing in particular, all of that.
$allowed = scopesAllowedFor($pdo, $p);
$requested = scopeList($request["scopes"]);
$scopes = $requested ? array_values(array_intersect($requested, $allowed)) : $allowed;

$clientName = $request["client_name"] ?: "An AI assistant";
$redirectHost = parse_url($request["redirect_uri"], PHP_URL_HOST) ?: "";

function redirectWith($uri, array $params)
{
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ""; });
    return $uri . (strpos($uri, "?") === false ? "?" : "&") . http_build_query($params);
}

if ($method === "GET") {
    $reason = null;
    if ($p["kind"] === "user" && !clientsMayUseMcp($pdo)) {
        $reason = "Connecting AI assistants to client accounts isn't switched on for this portal yet.";
    } elseif (!$scopes) {
        $reason = "Your account doesn't have access to anything this app asked for.";
    }

    oauthReply(200, [
        "success" => true,
        "client_name" => $clientName,
        "redirect_host" => $redirectHost,
        "principal" => [
            "kind" => $p["kind"],
            "name" => $p["name"],
            "email" => $p["email"],
            "role" => $p["role"],
        ],
        "scopes" => array_map(function ($s) {
            return ["scope" => $s, "description" => SCOPE_DESCRIPTIONS[$s] ?? $s];
        }, $scopes),
        "can_approve" => $reason === null,
        "reason" => $reason,
    ]);
}

if ($method !== "POST") {
    oauthReply(405, ["success" => false, "message" => "GET or POST only"]);
}

// Whatever the decision, this request is finished - delete it first, so a
// second click (or a second tab) can never mint a second code.
$claim = $pdo->prepare("DELETE FROM oauth_requests WHERE id = ?");
$claim->execute([$requestId]);
if ($claim->rowCount() !== 1) {
    oauthReply(409, ["success" => false, "message" => "This request was already answered."]);
}

$decision = (string) ($in["decision"] ?? "");
$canApprove = $scopes && ($p["kind"] !== "user" || clientsMayUseMcp($pdo));

if ($decision !== "approve" || !$canApprove) {
    oauthReply(200, [
        "success" => true,
        "redirect" => redirectWith($request["redirect_uri"], [
            "error" => "access_denied",
            "error_description" => $decision === "approve" ? "This account cannot grant access" : "The person declined",
            "state" => $request["state"],
        ]),
    ]);
}

$pdo->beginTransaction();
try {
    $pdo->prepare("
        INSERT INTO mcp_grants (client_id, principal_kind, principal_id, scopes, resource)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$request["client_id"], $p["kind"], $p["id"], implode(" ", $scopes), $request["resource"]]);
    $grantId = (int) $pdo->lastInsertId();

    $code = newOpaqueToken();
    $pdo->prepare("
        INSERT INTO oauth_codes (code_hash, grant_id, client_id, redirect_uri, code_challenge, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        tokenHash($code), $grantId, $request["client_id"],
        $request["redirect_uri"], $request["code_challenge"], time() + OAUTH_CODE_TTL,
    ]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    oauthReply(503, ["success" => false, "message" => "Couldn't finish connecting - please try again."]);
}

oauthReply(200, [
    "success" => true,
    "redirect" => redirectWith($request["redirect_uri"], ["code" => $code, "state" => $request["state"]]),
]);
