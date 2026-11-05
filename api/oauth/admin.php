<?php

/*
|--------------------------------------------------------------------------
| Client access - the toggle in Settings > Connected apps
|--------------------------------------------------------------------------
|
|   GET                                  -> { clients_enabled }
|   POST { clients_enabled: true|false } -> switch client access on or off
|
| clients_enabled: may clients (not only staff) connect an AI assistant to
| their own portal account? Off until the owner turns it on. Turning it off
| also ends every connection a client already has.
|
| What assistants have been doing is on Tracking (api/admin-events.php).
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
if (!can($p, "settings.manage", $pdo)) {
    oauthReply(403, ["success" => false, "message" => "Only the Director can change client access"]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    oauthReply(200, [
        "success" => true,
        "clients_enabled" => clientsMayUseMcp($pdo),
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
