<?php

/*
|--------------------------------------------------------------------------
| Settings > Permissions
|--------------------------------------------------------------------------
|
|   GET   the catalog, each role's column, every admin's exceptions and
|         where each of their answers comes from, and what the caller may
|         not change (permissionsPanel())
|   POST  {"batch": [{"role": "super_admin", "changes": {"content.create": false}},
|                    {"admin_id": 7, "changes": {"inquiries.read": true}}]}
|         One Save: all of it is stored, or none of it.
|         "dry_run": true checks it all and stores nothing.
|         The older one-at-a-time body ({"role": ...} or {"admin_id": ...}
|         at the top) is a batch of one.
|
| A change is true (allow), false (deny) or "default" - for a role, the
| portal's default; for an admin, whatever their role gets. The rules are
| in admin-settings-writes.php, shared with api/v1. Needs permissions.manage
| (the Director, or whoever the Director handed it to), from the browser only.
|
*/

require_once "db.php";
require_once "auth.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json; charset=UTF-8");

function permissionsReply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$p = requireAdmin($pdo, "permissions.manage", "Only the Director can change permissions.");

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    permissionsReply(true, "", ["data" => permissionsPanel($pdo, $p)]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    permissionsReply(false, "Invalid request.", [], 405);
}

// Before the transaction: creating the tables would commit it.
ensurePermissionTables($pdo);

$input = json_decode(file_get_contents("php://input"), true);
$input = is_array($input) ? $input : [];
$batch = array_key_exists("batch", $input) ? $input["batch"] : [$input];
$dryRun = ($input["dry_run"] ?? false) === true;

try {
    $pdo->beginTransaction();
    $names = savePermissionBatch($pdo, $p, $batch);

    if ($dryRun) {
        $pdo->rollBack();
        forgetPermissions();
        permissionsReply(true, "Nothing saved yet.", ["dry_run" => true]);
    }

    $pdo->commit();
} catch (PortalWriteError $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    forgetPermissions();
    permissionsReply(false, $e->getMessage(), [], $e->status);
}

forgetPermissions();
$message = count($names) === 1
    ? "Saved for " . $names[0] . "."
    : "Saved for " . implode(", ", array_slice($names, 0, -1)) . " and " . end($names) . ".";
permissionsReply(true, $message . " It applies on their next click - nobody is signed out.", ["data" => permissionsPanel($pdo, $p)]);
