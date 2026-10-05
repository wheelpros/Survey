<?php

/*
|--------------------------------------------------------------------------
| OAuth storage, for the W|ZONE MCP server only
|--------------------------------------------------------------------------
|
| Wzone-ai (MCP_MODE=private) speaks OAuth to MCP clients and calls this for
| every step that touches data. The shared X-MCP-Key is required: nothing
| here is reachable from a browser or by anyone holding only a token.
|
|   POST { "action": "...", ... }
|
|   register_client  { client }                     store a DCR registration
|   get_client       { client_id }                  -> { client, client_secret_hash }
|   create_request   { client_id, redirect_uri, code_challenge, scopes, state, resource }
|                                                   -> { request_id } for oauth-consent.html
|   challenge        { client_id, code }            -> { code_challenge }
|   exchange         { client_id, code, redirect_uri?, resource? }   -> tokens
|   refresh          { client_id, refresh_token, resource? }         -> tokens (rotated)
|   introspect       { token }                      -> { active, ... }
|   revoke           { client_id, token }           -> {}
|
| Errors use the OAuth error codes ({ error, error_description }) so the
| Node side can raise the matching SDK error.
*/

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    oauthReply(405, ["error" => "invalid_request", "error_description" => "POST only"]);
}
if (!isMcpServer()) {
    oauthReply(403, ["error" => "access_denied", "error_description" => "MCP server only"]);
}
if (rateLimited($pdo, "oauth-server", 600, 600)) {
    oauthReply(429, ["error" => "temporarily_unavailable", "error_description" => "Too many requests"]);
}

ensureOAuthTables($pdo);

$in = oauthJsonBody();
$action = (string) ($in["action"] ?? "");

function oauthError($status, $error, $description)
{
    oauthReply($status, ["error" => $error, "error_description" => $description]);
}

/**
 * A redirect URI we are willing to send codes to: https anywhere, or http
 * only on this machine (desktop clients and the MCP Inspector).
 */
function acceptableRedirectUri($uri)
{
    $parts = parse_url((string) $uri);
    if (!$parts || empty($parts["scheme"]) || empty($parts["host"]) || isset($parts["fragment"])) {
        return false;
    }
    if ($parts["scheme"] === "https") {
        return true;
    }
    return $parts["scheme"] === "http" && in_array($parts["host"], ["localhost", "127.0.0.1", "[::1]"], true);
}

function grantRow(PDO $pdo, $grantId)
{
    $stmt = $pdo->prepare("SELECT * FROM mcp_grants WHERE id = ? LIMIT 1");
    $stmt->execute([$grantId]);
    return $stmt->fetch() ?: null;
}

/**
 * The scopes a grant may carry right now: what was consented to, cut down
 * to what the person's role still allows. Null if the person is gone.
 */
function liveGrantScopes(PDO $pdo, array $grant)
{
    $p = loadPrincipal($pdo, $grant["principal_kind"], $grant["principal_id"]);
    if (!$p) {
        return null;
    }
    return array_values(array_intersect(scopeList($grant["scopes"]), scopesAllowedFor($pdo, $p)));
}

try {

    switch ($action) {

        case "register_client": {
            $client = $in["client"] ?? null;
            if (!is_array($client) || empty($client["client_id"]) || !preg_match('/^[A-Za-z0-9._-]{8,64}$/', $client["client_id"])) {
                oauthError(400, "invalid_client_metadata", "client_id missing or malformed");
            }
            $uris = $client["redirect_uris"] ?? [];
            if (!is_array($uris) || !$uris || count($uris) > 10) {
                oauthError(400, "invalid_redirect_uri", "redirect_uris must list 1 to 10 URIs");
            }
            foreach ($uris as $uri) {
                if (!acceptableRedirectUri($uri)) {
                    oauthError(400, "invalid_redirect_uri", "Redirect URIs must be https, or http on localhost");
                }
            }

            $secret = isset($client["client_secret"]) ? (string) $client["client_secret"] : "";
            unset($client["client_secret"]);
            $name = mb_substr(trim((string) ($client["client_name"] ?? "")), 0, 200);

            $pdo->prepare("
                INSERT INTO oauth_clients (client_id, client_secret_hash, client_name, metadata)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $client["client_id"],
                $secret !== "" ? tokenHash($secret) : null,
                $name !== "" ? $name : null,
                json_encode($client),
            ]);
            oauthReply(201, ["ok" => true]);
        }

        case "get_client": {
            $stmt = $pdo->prepare("SELECT * FROM oauth_clients WHERE client_id = ? LIMIT 1");
            $stmt->execute([(string) ($in["client_id"] ?? "")]);
            $row = $stmt->fetch();
            if (!$row) {
                oauthReply(404, ["error" => "invalid_client", "error_description" => "Unknown client"]);
            }
            oauthReply(200, [
                "client" => json_decode($row["metadata"], true),
                "client_secret_hash" => $row["client_secret_hash"],
            ]);
        }

        case "create_request": {
            $clientId = (string) ($in["client_id"] ?? "");
            $stmt = $pdo->prepare("SELECT 1 FROM oauth_clients WHERE client_id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            if (!$stmt->fetchColumn()) {
                oauthError(400, "invalid_client", "Unknown client");
            }
            $challenge = (string) ($in["code_challenge"] ?? "");
            if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
                oauthError(400, "invalid_request", "code_challenge is required (S256)");
            }

            cleanupOAuth($pdo);
            $id = newOpaqueToken();
            $pdo->prepare("
                INSERT INTO oauth_requests (id, client_id, redirect_uri, code_challenge, scopes, state, resource, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id,
                $clientId,
                (string) ($in["redirect_uri"] ?? ""),
                $challenge,
                implode(" ", scopeList($in["scopes"] ?? [])),
                isset($in["state"]) ? mb_substr((string) $in["state"], 0, 2000) : null,
                isset($in["resource"]) ? mb_substr((string) $in["resource"], 0, 255) : null,
                time() + OAUTH_REQUEST_TTL,
            ]);
            oauthReply(200, ["request_id" => $id]);
        }

        case "challenge": {
            // The SDK asks for this before exchange (to check PKCE itself),
            // so a replayed code first shows up here - and has to end the
            // grant here, or exchange's replay handling would never run.
            $stmt = $pdo->prepare("SELECT * FROM oauth_codes WHERE code_hash = ? LIMIT 1");
            $stmt->execute([tokenHash($in["code"] ?? "")]);
            $code = $stmt->fetch();

            if (!$code || $code["client_id"] !== (string) ($in["client_id"] ?? "")) {
                oauthError(400, "invalid_grant", "Invalid authorization code");
            }
            if ($code["used_at"] !== null) {
                revokeGrant($pdo, (int) $code["grant_id"]);
                oauthError(400, "invalid_grant", "Authorization code already used");
            }
            if ((int) $code["expires_at"] <= time()) {
                oauthError(400, "invalid_grant", "Authorization code expired");
            }
            oauthReply(200, ["code_challenge" => $code["code_challenge"]]);
        }

        case "exchange": {
            $codeHash = tokenHash($in["code"] ?? "");
            $clientId = (string) ($in["client_id"] ?? "");

            $stmt = $pdo->prepare("SELECT * FROM oauth_codes WHERE code_hash = ? LIMIT 1");
            $stmt->execute([$codeHash]);
            $code = $stmt->fetch();

            if (!$code || $code["client_id"] !== $clientId) {
                oauthError(400, "invalid_grant", "Invalid authorization code");
            }
            if ($code["used_at"] !== null) {
                // A code used twice means someone else has it too. RFC 6749
                // 4.1.2: revoke everything issued from it.
                revokeGrant($pdo, (int) $code["grant_id"]);
                oauthError(400, "invalid_grant", "Authorization code already used");
            }
            if ((int) $code["expires_at"] <= time()) {
                oauthError(400, "invalid_grant", "Authorization code expired");
            }
            if (isset($in["redirect_uri"]) && $in["redirect_uri"] !== $code["redirect_uri"]) {
                oauthError(400, "invalid_grant", "redirect_uri does not match the authorization request");
            }

            $grant = grantRow($pdo, (int) $code["grant_id"]);
            if (!$grant || $grant["revoked_at"] !== null) {
                oauthError(400, "invalid_grant", "Authorization was revoked");
            }
            if (isset($in["resource"]) && $grant["resource"] !== null && $in["resource"] !== $grant["resource"]) {
                oauthError(400, "invalid_target", "Token requested for a different resource");
            }

            // Claim the code - exactly one exchange wins a race.
            $claim = $pdo->prepare("UPDATE oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL");
            $claim->execute([time(), $codeHash]);
            if ($claim->rowCount() !== 1) {
                revokeGrant($pdo, (int) $code["grant_id"]);
                oauthError(400, "invalid_grant", "Authorization code already used");
            }

            $scopes = liveGrantScopes($pdo, $grant);
            if (!$scopes) {
                revokeGrant($pdo, (int) $grant["id"]);
                oauthError(400, "invalid_grant", "This account can no longer grant access");
            }
            oauthReply(200, issueTokenPair($pdo, (int) $grant["id"], $scopes));
        }

        case "refresh": {
            $presented = (string) ($in["refresh_token"] ?? "");
            $clientId = (string) ($in["client_id"] ?? "");
            $live = findLiveMcpToken($pdo, $presented, "refresh");

            if (!$live) {
                // A refresh token that exists but was already rotated away is
                // being replayed - by the client after a crash, or by a thief.
                // Either way the safe answer is to end the whole grant.
                $stmt = $pdo->prepare("SELECT grant_id FROM mcp_tokens WHERE token_hash = ? AND kind = 'refresh' LIMIT 1");
                $stmt->execute([tokenHash($presented)]);
                $grantId = $stmt->fetchColumn();
                if ($grantId) {
                    revokeGrant($pdo, (int) $grantId);
                }
                oauthError(400, "invalid_grant", "Invalid refresh token");
            }
            if ($live["client_id"] !== $clientId) {
                oauthError(400, "invalid_grant", "Refresh token was issued to another client");
            }
            if (isset($in["resource"]) && $live["resource"] !== null && $in["resource"] !== $live["resource"]) {
                oauthError(400, "invalid_target", "Token requested for a different resource");
            }

            $grant = grantRow($pdo, (int) $live["grant_id"]);
            $scopes = $grant ? liveGrantScopes($pdo, $grant) : null;
            if (!$scopes) {
                revokeGrant($pdo, (int) $live["grant_id"]);
                oauthError(400, "invalid_grant", "This account can no longer grant access");
            }

            $rotate = $pdo->prepare("UPDATE mcp_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL");
            $rotate->execute([time(), $live["token_id"]]);
            if ($rotate->rowCount() !== 1) {
                revokeGrant($pdo, (int) $live["grant_id"]);
                oauthError(400, "invalid_grant", "Invalid refresh token");
            }
            oauthReply(200, issueTokenPair($pdo, (int) $live["grant_id"], $scopes));
        }

        case "introspect": {
            $token = (string) ($in["token"] ?? "");
            $p = isMcpAccessTokenShape($token) ? principalFromMcpToken($pdo, $token) : null;
            if (!$p) {
                oauthReply(200, ["active" => false]);
            }
            oauthReply(200, [
                "active" => true,
                "client_id" => $p["client_id"],
                "client_name" => $p["client_name"],
                "scopes" => $p["scopes"],
                "expires_at" => $p["expires_at"],
                "resource" => $p["resource"],
                "grant_id" => $p["grant_id"],
                "principal" => [
                    "kind" => $p["kind"],
                    "id" => $p["id"],
                    "name" => $p["name"],
                    "role" => $p["role"],
                ],
            ]);
        }

        case "revoke": {
            $stmt = $pdo->prepare("
                SELECT t.grant_id, g.client_id FROM mcp_tokens t
                JOIN mcp_grants g ON g.id = t.grant_id
                WHERE t.token_hash = ? LIMIT 1
            ");
            $stmt->execute([tokenHash($in["token"] ?? "")]);
            $row = $stmt->fetch();
            // RFC 7009: an unknown token is not an error.
            if ($row && $row["client_id"] === (string) ($in["client_id"] ?? "")) {
                revokeGrant($pdo, (int) $row["grant_id"]);
            }
            oauthReply(200, ["ok" => true]);
        }

        default:
            oauthError(400, "invalid_request", "Unknown action");
    }

} catch (PDOException $e) {
    oauthError(503, "temporarily_unavailable", "Please try again shortly");
}
