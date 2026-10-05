<?php

/*
|--------------------------------------------------------------------------
| AI access - the owner's controls
|--------------------------------------------------------------------------
|
|   GET                                  -> { clients_enabled, activity: [...] }
|   POST { clients_enabled: true|false } -> switch client access on or off
|
| clients_enabled: may clients (not only staff) connect an AI assistant to
| their own portal account? Off until the owner turns it on. Turning it off
| also ends every connection a client already has.
|
| activity: the latest calls AI assistants made into the portal
| (mcp_audit_log), newest first - who, through which app, which tool, and
| how it went.
|
| The owner's browser session only.
*/

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth.php";

ensureOAuthTables($pdo);

$p = currentPrincipal($pdo);
if (!$p || $p["via"] !== "session") {
    oauthReply(401, ["success" => false, "message" => "Please sign in"]);
}
if ($p["kind"] !== "admin" || $p["role"] !== "owner") {
    oauthReply(403, ["success" => false, "message" => "Only the owner can change AI access"]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stmt = $pdo->query("
        SELECT l.created_at, l.principal_kind, l.tool, l.endpoint, l.method, l.status,
               COALESCE(a.name, u.name) AS person,
               c.client_name
        FROM mcp_audit_log l
        LEFT JOIN admins a ON l.principal_kind = 'admin' AND a.id = l.principal_id
        LEFT JOIN users u ON l.principal_kind = 'user' AND u.id = l.principal_id
        LEFT JOIN oauth_clients c ON c.client_id = l.client_id
        ORDER BY l.id DESC
        LIMIT 50
    ");

    oauthReply(200, [
        "success" => true,
        "clients_enabled" => clientsMayUseMcp($pdo),
        "activity" => array_map(function ($r) {
            return [
                "at" => $r["created_at"],
                "person" => $r["person"] ?: "(deleted)",
                "kind" => $r["principal_kind"],
                "app" => $r["client_name"] ?: "AI assistant",
                "tool" => $r["tool"],
                "endpoint" => $r["endpoint"],
                "status" => (int) $r["status"],
            ];
        }, $stmt->fetchAll()),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $in = oauthJsonBody();
    if (!array_key_exists("clients_enabled", $in)) {
        oauthReply(400, ["success" => false, "message" => "clients_enabled is required"]);
    }
    $enabled = $in["clients_enabled"] === true;

    $error = null;
    if (!setSiteSetting($pdo, "mcp_clients_enabled", $enabled ? "1" : "0", $error)) {
        oauthReply(500, ["success" => false, "message" => $error ?: "Couldn't save the setting"]);
    }

    $ended = 0;
    if (!$enabled) {
        $stmt = $pdo->query("SELECT id FROM mcp_grants WHERE principal_kind = 'user' AND revoked_at IS NULL");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $grantId) {
            revokeGrant($pdo, (int) $grantId);
            $ended++;
        }
    }

    oauthReply(200, [
        "success" => true,
        "clients_enabled" => $enabled,
        "message" => $enabled
            ? "Clients can now connect AI assistants to their accounts."
            : "Client access is off" . ($ended ? " - $ended client connection" . ($ended === 1 ? "" : "s") . " ended." : "."),
    ]);
}

oauthReply(405, ["success" => false, "message" => "GET or POST only"]);
