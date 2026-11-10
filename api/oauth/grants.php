<?php

/*
|--------------------------------------------------------------------------
| Connected apps - the AI assistants a person has given access to
|--------------------------------------------------------------------------
|
|   GET                         -> { grants: [{ id, client_name, scopes, missing, created_at, last_used_at }] }
|   POST { action: "revoke", id }
|
| scopes is what the connection can do today: what was approved, within what
| the person's role still allows. missing is what the role allows but this
| connection was never given.
|
| Browser sessions only, and only your own grants: an AI holding a token
| must not be able to list or end other connections. Revoking takes effect
| on the MCP server within a minute (it caches token checks for 60s).
*/

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth.php";

ensureOAuthTables($pdo);

$p = currentPrincipal($pdo);
if (!$p || $p["via"] !== "session") {
    oauthReply(401, ["success" => false, "message" => "Please sign in"]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stmt = $pdo->prepare("
        SELECT g.id, g.scopes, g.created_at, g.last_used_at, c.client_name
        FROM mcp_grants g
        LEFT JOIN oauth_clients c ON c.client_id = g.client_id
        WHERE g.principal_kind = ? AND g.principal_id = ? AND g.revoked_at IS NULL
          AND EXISTS (
              SELECT 1 FROM mcp_tokens t
              WHERE t.grant_id = g.id AND t.revoked_at IS NULL AND t.expires_at > ?
          )
        ORDER BY g.created_at DESC
    ");
    $stmt->execute([$p["kind"], $p["id"], time()]);
    $allowed = scopesAllowedFor($pdo, $p);

    oauthReply(200, [
        "success" => true,
        // A connection can do what its person may do today (see
        // principalFromMcpToken()), whatever was on offer when it was approved.
        "grants" => array_map(function ($g) use ($allowed) {
            return [
                "id" => (int) $g["id"],
                "client_name" => $g["client_name"] ?: "AI assistant",
                "scopes" => array_map("describeScope", $allowed),
                "created_at" => $g["created_at"],
                "last_used_at" => $g["last_used_at"],
            ];
        }, $stmt->fetchAll()),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $in = oauthJsonBody();
    if (($in["action"] ?? "") !== "revoke") {
        oauthReply(400, ["success" => false, "message" => "Unknown action"]);
    }
    $stmt = $pdo->prepare("
        SELECT id FROM mcp_grants
        WHERE id = ? AND principal_kind = ? AND principal_id = ? AND revoked_at IS NULL
    ");
    $stmt->execute([(int) ($in["id"] ?? 0), $p["kind"], $p["id"]]);
    $grantId = $stmt->fetchColumn();
    if (!$grantId) {
        oauthReply(404, ["success" => false, "message" => "That connection was not found"]);
    }
    revokeGrant($pdo, (int) $grantId);
    oauthReply(200, ["success" => true, "message" => "Disconnected."]);
}

oauthReply(405, ["success" => false, "message" => "GET or POST only"]);
