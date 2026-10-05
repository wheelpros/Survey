<?php

/*
| Changes - how an AI assistant writes anything, in two steps.
|
|   POST changes.php {"action": "prepare", "tool": "...", "args": {...}}
|       Checks the change as this person, against the portal as it is now,
|       and writes nothing. Returns what will happen (summary, preview) and
|       a confirmation_token, valid for 15 minutes, for this person only.
|
|   POST changes.php {"action": "confirm", "confirmation_token": "...",
|                     "summary": "..."}
|       Makes exactly the change that was prepared - the arguments are kept
|       here, not sent back, so nothing can be altered in between. The
|       summary must be the one prepare returned: it is what the person's AI
|       client shows them when asking to approve the call, so they approve
|       the change itself rather than an opaque token. Checked again first
|       (scope, visibility, state); then applied once. A repeat with the
|       same token answers with the first result and changes nothing.
|
| Staff only for now; the handlers are in _writes.php.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_writes.php";

$p = v1Principal($pdo);
v1RequireMethod("POST");
v1RequireStaff($p);

// DDL before anything that could open a transaction (notify.php rule 1).
ensureMcpConfirmations($pdo);
ensureNotificationsTable($pdo);

$raw = file_get_contents("php://input");
if (strlen($raw) > 65536) {
    v1Error(413, "too_large", "That request is too large.");
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    v1Error(422, "invalid_body", "Send a JSON object.");
}

$handlers = v1WriteHandlers();

/** Runs a handler's prepare, turning a refusal into the reply. */
function runPrepare(array $handler, PDO $pdo, array $p, array $args)
{
    try {
        $prepared = $handler["prepare"]($pdo, $p, $args);
    } catch (PortalWriteError $e) {
        v1Error($e->status, "change_refused", $e->getMessage());
    }
    return $prepared;
}

$action = (string) ($in["action"] ?? "");

if ($action === "prepare") {
    $tool = (string) ($in["tool"] ?? "");
    if (!isset($handlers[$tool])) {
        v1Error(404, "unknown_change", "There is no change called '$tool'.");
    }
    v1RequireScope($p, $handlers[$tool]["scope"]);

    $args = $in["args"] ?? [];
    if (!is_array($args) || ($args !== [] && array_values($args) === $args)) {
        v1Error(422, "invalid_parameter", "args must be an object.");
    }

    $prepared = runPrepare($handlers[$tool], $pdo, $p, $args);

    $token = "wzct_" . rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
    $expires = time() + V1_CONFIRM_TTL;

    $pdo->prepare("
        INSERT INTO mcp_confirmations
            (token_hash, principal_kind, principal_id, grant_id, tool, args, summary, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        hash("sha256", $token), $p["kind"], $p["id"], $p["grant_id"] ?? null,
        $tool, json_encode($prepared["args"]), $prepared["summary"], $expires,
    ]);

    v1Reply(200, [
        "tool" => $tool,
        "summary" => $prepared["summary"],
        "preview" => $prepared["preview"],
        "confirmation_token" => $token,
        "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", $expires),
    ]);
}

if ($action !== "confirm") {
    v1Error(422, "invalid_parameter", "action must be 'prepare' or 'confirm'.");
}

$token = (string) ($in["confirmation_token"] ?? "");
$row = null;
if (preg_match('/^wzct_[A-Za-z0-9_-]{43}$/', $token)) {
    $stmt = $pdo->prepare("SELECT * FROM mcp_confirmations WHERE token_hash = ? LIMIT 1");
    $stmt->execute([hash("sha256", $token)]);
    $row = $stmt->fetch();
}

// Only the person who prepared it, through the same connection.
if (!$row
    || $row["principal_kind"] !== $p["kind"]
    || (int) $row["principal_id"] !== (int) $p["id"]
    || (int) ($row["grant_id"] ?? 0) !== (int) ($p["grant_id"] ?? 0)) {
    v1Error(404, "unknown_confirmation", "That confirmation isn't valid here. Prepare the change again.");
}

if (trim((string) ($in["summary"] ?? "")) !== trim($row["summary"])) {
    v1Error(422, "summary_mismatch", "Pass back the summary exactly as prepare returned it - it is what the person approves.");
}

function doneReply(array $row, $duplicate)
{
    $result = json_decode((string) $row["result"], true);
    if (!is_array($result)) {
        v1Error(409, "in_progress", "That change is still being made. Check again in a moment.");
    }
    if (empty($result["ok"])) {
        v1Error((int) ($result["status"] ?? 409), "change_refused", "This change was already tried and refused: " . ($result["message"] ?? ""));
    }
    v1Reply(200, ["done" => true, "duplicate" => $duplicate, "tool" => $row["tool"], "summary" => $row["summary"], "result" => $result["data"]]);
}

if ($row["used_at"] !== null) {
    doneReply($row, true);
}
if ((int) $row["expires_at"] < time()) {
    v1Error(410, "expired", "That confirmation has expired. Prepare the change again and re-confirm with the person.");
}

$handler = $handlers[$row["tool"]] ?? null;
if (!$handler) {
    v1Error(404, "unknown_change", "That change is no longer offered.");
}
v1RequireScope($p, $handler["scope"]);

// Everything checked again against the portal as it is now - a form someone
// reviewed in the meantime, or a client moved off this admin, stops here.
$args = json_decode($row["args"], true) ?: [];
runPrepare($handler, $pdo, $p, $args);

// Claim it. Of two confirms racing, exactly one gets here with rowCount 1.
$claim = $pdo->prepare("UPDATE mcp_confirmations SET used_at = ? WHERE id = ? AND used_at IS NULL");
$claim->execute([time(), $row["id"]]);
if ($claim->rowCount() !== 1) {
    $again = $pdo->prepare("SELECT * FROM mcp_confirmations WHERE id = ?");
    $again->execute([$row["id"]]);
    doneReply($again->fetch(), true);
}

$record = function (array $outcome) use ($pdo, $row) {
    $pdo->prepare("UPDATE mcp_confirmations SET result = ? WHERE id = ?")
        ->execute([json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $row["id"]]);
};

try {
    $result = $handler["apply"]($pdo, $p, $args);
} catch (PortalWriteError $e) {
    $record(["ok" => false, "status" => $e->status, "message" => $e->getMessage()]);
    v1Error($e->status, "change_refused", $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("api/v1/changes.php {$row["tool"]} failed: " . $e->getMessage());
    $record(["ok" => false, "status" => 500, "message" => "The change failed."]);
    v1Error(500, "change_failed", "The change failed. Nothing more will happen with this confirmation - prepare it again to retry.");
}

$record(["ok" => true, "data" => $result]);
v1Reply(200, ["done" => true, "duplicate" => false, "tool" => $row["tool"], "summary" => $row["summary"], "result" => $result]);
