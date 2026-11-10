<?php

/*
|--------------------------------------------------------------------------
| api/v1 - the AI-facing API
|--------------------------------------------------------------------------
|
| What the authenticated MCP server (Wzone-ai, MCP_MODE=private) calls with
| the person's own access token. Kept apart from the ~50 browser endpoints
| so those can keep changing shape for the pages without breaking the AI,
| and so the AI gets a stable contract:
|
|   success  { "ok": true,  "data": ... }
|   failure  { "ok": false, "error": { "code": "...", "message": "..." } }
|            with a real HTTP status - 401, 403, 404, 422 - never a 200
|            with success:false.
|
| Every endpoint starts with:
|
|   require_once __DIR__ . "/_bootstrap.php";
|   $p = v1Principal($pdo);                 // 401 if nobody
|   v1RequireScope($p, "clients:read");     // 403 if not consented / allowed
|
| An MCP access token is only accepted when the request also carries the
| MCP server's X-MCP-Key: a token copied out of a client can't be replayed
| against this API from anywhere else. Every MCP call is written to
| mcp_audit_log when the response goes out.
*/

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/_helpers.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

/*
| Every reply is JSON, even when something breaks. A PHP warning printed into
| the page, an uncaught exception or a fatal error used to reach the MCP
| server as HTML or an empty body ("wzone_api_non_json_response"), with
| nothing saying where. Now: notices go to the error log instead of the body,
| anything buffered before the reply is dropped, and a crash is logged with
| the endpoint, the tool and the request id, and answered with a 500 whose
| message carries that request id so a report can be matched to the log.
*/
ini_set("display_errors", "0");
define("V1_OB_BASE", ob_get_level());
ob_start();

/** Endpoint, tool and request id of this call, for log lines. */
function v1RequestContext()
{
    $tool = $_SERVER["HTTP_X_MCP_TOOL"] ?? "";
    $requestId = $_SERVER["HTTP_X_REQUEST_ID"] ?? "";
    return [
        "endpoint" => ($_SERVER["REQUEST_METHOD"] ?? "GET") . " " . mb_substr((string) ($_SERVER["REQUEST_URI"] ?? ""), 0, 200),
        "tool" => preg_match('/^[a-z0-9_]{1,80}$/', $tool) ? $tool : "-",
        "request_id" => preg_match('/^[A-Za-z0-9-]{1,64}$/', $requestId) ? $requestId : bin2hex(random_bytes(8)),
    ];
}

/** Drops whatever was printed before the reply - warnings, half a page.
    Buffers the server opened itself (compression) stay. */
function v1DiscardOutput()
{
    while (ob_get_level() > V1_OB_BASE + 1) {
        ob_end_clean();
    }
    if (ob_get_level() > V1_OB_BASE) {
        ob_clean();
    }
}

function v1ServerError($detail)
{
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;

    $ctx = v1RequestContext();
    error_log(sprintf(
        "api/v1 server_error endpoint=%s tool=%s request_id=%s: %s",
        $ctx["endpoint"], $ctx["tool"], $ctx["request_id"], $detail
    ));

    v1DiscardOutput();
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode(["ok" => false, "error" => [
        "code" => "server_error",
        "message" => "Something went wrong on the portal. Reference: " . $ctx["request_id"],
    ]]);
}

set_exception_handler(function (Throwable $e) {
    v1ServerError(get_class($e) . ": " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine()
        . "\n" . $e->getTraceAsString());
});

/* Registered before v1StartAudit's, so it runs first: the audit row then
   records the 500 rather than the 200 a fatal error leaves behind. */
register_shutdown_function(function () {
    $error = error_get_last();
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ($error && ($error["type"] & $fatal)) {
        v1ServerError("PHP fatal error: " . $error["message"] . " at " . $error["file"] . ":" . $error["line"]);
    }
});

function v1Reply($status, $data)
{
    v1DiscardOutput();
    http_response_code($status);
    // Substitute rather than fail: one stray byte in a client's answer must
    // not turn the whole reply into an empty body.
    $json = json_encode(["ok" => true, "data" => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        // echo false is an empty body; say why in the log instead.
        v1ServerError("json_encode failed: " . json_last_error_msg());
        exit;
    }
    echo $json;
    exit;
}

function v1Error($status, $code, $message)
{
    v1DiscardOutput();
    http_response_code($status);
    if ($status === 401) {
        header('WWW-Authenticate: Bearer error="invalid_token"');
    }
    echo json_encode(["ok" => false, "error" => ["code" => $code, "message" => $message]]);
    exit;
}

function v1Principal(PDO $pdo)
{
    $p = currentPrincipal($pdo);
    if (!$p) {
        v1Error(401, "unauthorized", "Sign in again - this token is missing, expired or revoked.");
    }
    if ($p["via"] === "mcp") {
        if (!isMcpServer()) {
            v1Error(401, "unauthorized", "AI access tokens are only accepted through the MCP server.");
        }
        v1StartAudit($pdo, $p);
    }
    return $p;
}

function v1RequireScope(array $p, $scope)
{
    if (!hasScope($p, $scope)) {
        v1Error(403, "insufficient_scope", "This connection isn't allowed to do that ($scope). Reconnect and approve it, or ask the Director about your role.");
    }
}

/**
 * Records this MCP call in mcp_audit_log once the response status is
 * known, and marks the grant as used. Never lets logging fail a request.
 */
function v1StartAudit(PDO $pdo, array $p)
{
    $tool = preg_match('/^[a-z0-9_]{1,80}$/', $_SERVER["HTTP_X_MCP_TOOL"] ?? "") ? $_SERVER["HTTP_X_MCP_TOOL"] : null;
    $requestId = preg_match('/^[A-Za-z0-9-]{1,64}$/', $_SERVER["HTTP_X_REQUEST_ID"] ?? "") ? $_SERVER["HTTP_X_REQUEST_ID"] : null;
    $endpoint = mb_substr(parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?: "", 0, 120);
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    register_shutdown_function(function () use ($pdo, $p, $tool, $requestId, $endpoint, $method) {
        try {
            $pdo->prepare("
                INSERT INTO mcp_audit_log
                    (grant_id, client_id, principal_kind, principal_id, tool, endpoint, method, status, request_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $p["grant_id"], $p["client_id"], $p["kind"], $p["id"],
                $tool, $endpoint, $method, http_response_code() ?: 200, $requestId,
            ]);
            $pdo->prepare("UPDATE mcp_grants SET last_used_at = NOW() WHERE id = ?")
                ->execute([$p["grant_id"]]);
        } catch (PDOException $e) {
            // An audit row is worth a lot, but not a failed request.
        }
    });
}
