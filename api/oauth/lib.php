<?php

/*
|--------------------------------------------------------------------------
| OAuth for the W|ZONE MCP server - storage, tokens, scopes
|--------------------------------------------------------------------------
|
| Who does what:
|
|   Wzone-ai (Node, MCP_MODE=private)  speaks the OAuth protocol to Claude and
|       other MCP clients: metadata, /register, /authorize, /token, /revoke.
|       It holds no data; every step calls api/oauth/server.php with the
|       shared X-MCP-Key.
|   api/oauth/server.php   the storage behind those steps (MCP server only)
|   oauth-consent.html     the page a person approves access on - it has to
|       be on this domain, because a portal session lives in this domain's
|       localStorage - backed by api/oauth/consent.php
|   api/oauth/grants.php   "Connected apps": list and revoke your own grants
|   api/auth.php           turns an access token back into a person, on every
|       api/v1 request
|
| Tables (created lazily, like everything else in api/db.php):
|
|   oauth_clients    apps registered through dynamic client registration
|   oauth_requests   an /authorize waiting for the person to decide
|   oauth_codes      a decided authorization, waiting to be exchanged
|   mcp_grants       one per "connect" - what was consented to, by whom;
|                    revoking it kills every token issued under it
|   mcp_tokens       access (1 hour) and refresh (30 days, rotated) tokens,
|                    stored as sha256 only
|   mcp_audit_log    one row per AI call into api/v1
|
| Tokens are separate from browser sessions on purpose: connecting Claude
| never signs anyone out, an AI token expires, and it can be revoked
| without touching the person's login.
*/

require_once __DIR__ . "/../rate-limit.php";

const STAFF_SCOPES = [
    "clients:read", "clients:write",
    "forms:read", "forms:write", "forms:review",
    "inquiries:read", "inquiries:write",
    "content:read", "content:write",
    "calendar:read", "calendar:write",
    "projects:read", "projects:write",
    "notifications:read", "notifications:write",
];

const CLIENT_SCOPES = ["self:read", "self:write"];

// Shown on the consent page, one line per scope.
const SCOPE_DESCRIPTIONS = [
    "clients:read"        => "See the clients you manage, their profiles and teams",
    "clients:write"       => "Save your private notes about clients",
    "forms:read"          => "See forms and the answers clients gave",
    "forms:write"         => "Draft forms for your clients (they still go through review)",
    "forms:review"        => "Approve or return forms waiting for review",
    "inquiries:read"      => "See consultation inquiries and their leads",
    "inquiries:write"     => "Draft new consultation inquiries",
    "content:read"        => "See content and the content calendar",
    "content:write"       => "Draft content (drafts are never published automatically)",
    "calendar:read"       => "See meetings and requests waiting for approval",
    "calendar:write"      => "Propose meetings and answer meeting requests",
    "projects:read"       => "See projects, their tasks and updates",
    "projects:write"      => "Post project updates and change tasks",
    "notifications:read"  => "See your notifications",
    "notifications:write" => "Mark your notifications as read",
    "self:read"           => "See your meetings, content, forms, projects and notifications",
    "self:write"          => "Request and answer meetings, fill in your forms, mark notifications read",
];

const OAUTH_REQUEST_TTL = 900;            // to approve or deny on the consent page
const OAUTH_CODE_TTL = 600;               // to exchange a code for tokens
const MCP_ACCESS_TTL = 3600;              // 1 hour
const MCP_REFRESH_TTL = 30 * 24 * 3600;   // 30 days, rotated on every use

function oauthReply($status, array $body)
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store");
    echo json_encode($body);
    exit;
}

function oauthJsonBody()
{
    $input = json_decode(file_get_contents("php://input"), true);
    return is_array($input) ? $input : [];
}

function ensureOAuthTables(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $statements = [
        "CREATE TABLE IF NOT EXISTS oauth_clients (
            client_id          VARCHAR(64)  NOT NULL PRIMARY KEY,
            client_secret_hash CHAR(64)         NULL,
            client_name        VARCHAR(200)     NULL,
            metadata           TEXT         NOT NULL,
            created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS oauth_requests (
            id             CHAR(43)     NOT NULL PRIMARY KEY,
            client_id      VARCHAR(64)  NOT NULL,
            redirect_uri   TEXT         NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            scopes         TEXT             NULL,
            state          TEXT             NULL,
            resource       VARCHAR(255)     NULL,
            expires_at     INT          NOT NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS oauth_codes (
            code_hash      CHAR(64)     NOT NULL PRIMARY KEY,
            grant_id       INT          NOT NULL,
            client_id      VARCHAR(64)  NOT NULL,
            redirect_uri   TEXT         NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            expires_at     INT          NOT NULL,
            used_at        INT              NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS mcp_grants (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            client_id      VARCHAR(64)  NOT NULL,
            principal_kind VARCHAR(10)  NOT NULL,
            principal_id   INT          NOT NULL,
            scopes         TEXT         NOT NULL,
            resource       VARCHAR(255)     NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at   DATETIME         NULL,
            revoked_at     DATETIME         NULL,
            KEY idx_principal (principal_kind, principal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS mcp_tokens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64)    NOT NULL,
            kind       VARCHAR(10) NOT NULL,
            grant_id   INT         NOT NULL,
            expires_at INT         NOT NULL,
            revoked_at INT             NULL,
            created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token (token_hash),
            KEY idx_grant (grant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS mcp_audit_log (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            grant_id       INT          NOT NULL,
            client_id      VARCHAR(64)  NOT NULL,
            principal_kind VARCHAR(10)  NOT NULL,
            principal_id   INT          NOT NULL,
            tool           VARCHAR(80)      NULL,
            endpoint       VARCHAR(120) NOT NULL,
            method         VARCHAR(10)  NOT NULL,
            status         INT          NOT NULL,
            request_id     VARCHAR(64)      NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_principal (principal_kind, principal_id, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    try {
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    } catch (PDOException $e) {
        // Read-only DB user: every OAuth step then fails on its own first
        // query and reports "temporarily unavailable" rather than dying here.
    }
}

/** A new random secret: "<prefix>" + 43 base64url characters (256 bits). */
function newOpaqueToken($prefix = "")
{
    return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
}

function tokenHash($token)
{
    return hash("sha256", (string) $token);
}

function isMcpAccessTokenShape($token)
{
    return (bool) preg_match('/^wzat_[A-Za-z0-9_-]{43}$/', (string) $token);
}

/** Scopes as stored (space separated) to a clean, de-duplicated list. */
function scopeList($value)
{
    $list = is_array($value) ? $value : preg_split('/\s+/', trim((string) $value));
    return array_values(array_unique(array_filter($list, function ($s) {
        return is_string($s) && preg_match('/^[a-z]+:[a-z]+$/', $s);
    })));
}

/**
 * Owner setting, off by default: may clients (not just staff) connect an AI
 * assistant to their portal account?
 */
function clientsMayUseMcp(PDO $pdo)
{
    return function_exists("getSiteSetting") && getSiteSetting($pdo, "mcp_clients_enabled", "0") === "1";
}

/**
 * A live token row joined to its grant and client, or null. Live means: the
 * right kind, not expired, not revoked, and its grant not revoked.
 */
function findLiveMcpToken(PDO $pdo, $token, $kind)
{
    ensureOAuthTables($pdo);
    $stmt = $pdo->prepare("
        SELECT t.id AS token_id, t.grant_id, t.expires_at,
               g.client_id, g.principal_kind, g.principal_id, g.scopes, g.resource,
               c.client_name
        FROM mcp_tokens t
        JOIN mcp_grants g ON g.id = t.grant_id
        LEFT JOIN oauth_clients c ON c.client_id = g.client_id
        WHERE t.token_hash = ? AND t.kind = ?
          AND t.revoked_at IS NULL AND t.expires_at > ?
          AND g.revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([tokenHash($token), $kind, time()]);
    return $stmt->fetch() ?: null;
}

/**
 * Issues a fresh access + refresh pair under a grant. Returns the OAuth
 * token response body (RFC 6749 5.1) - the only time either secret exists
 * in clear.
 */
function issueTokenPair(PDO $pdo, $grantId, array $scopes)
{
    $access = newOpaqueToken("wzat_");
    $refresh = newOpaqueToken("wzrt_");
    $now = time();

    $insert = $pdo->prepare("
        INSERT INTO mcp_tokens (token_hash, kind, grant_id, expires_at) VALUES (?, ?, ?, ?)
    ");
    $insert->execute([tokenHash($access), "access", $grantId, $now + MCP_ACCESS_TTL]);
    $insert->execute([tokenHash($refresh), "refresh", $grantId, $now + MCP_REFRESH_TTL]);

    return [
        "access_token" => $access,
        "token_type" => "Bearer",
        "expires_in" => MCP_ACCESS_TTL,
        "refresh_token" => $refresh,
        "scope" => implode(" ", $scopes),
    ];
}

/** Revokes a grant and every token under it. */
function revokeGrant(PDO $pdo, $grantId)
{
    $pdo->prepare("UPDATE mcp_grants SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL")
        ->execute([$grantId]);
    $pdo->prepare("UPDATE mcp_tokens SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL")
        ->execute([time(), $grantId]);
}

/** Opportunistic cleanup of finished requests, codes and long-dead tokens. */
function cleanupOAuth(PDO $pdo)
{
    try {
        $now = time();
        $pdo->prepare("DELETE FROM oauth_requests WHERE expires_at < ?")->execute([$now]);
        $pdo->prepare("DELETE FROM oauth_codes WHERE expires_at < ?")->execute([$now - 3600]);
        $pdo->prepare("DELETE FROM mcp_tokens WHERE expires_at < ?")->execute([$now - 7 * 24 * 3600]);
    } catch (PDOException $e) {
        // Cleanup is never worth failing a request over.
    }
}
