<?php

/*
|--------------------------------------------------------------------------
| Settings > Permissions
|--------------------------------------------------------------------------
|
|   GET   the catalog, each role's column and every admin's exceptions
|   POST  {"role": "super_admin", "changes": {"content.create": false}}
|         {"admin_id": 7, "changes": {"inquiries.read": true}}
|
| A change is true (allow), false (deny) or "default" - for a role, the
| portal's default; for an admin, whatever their role gets. The rules are
| in admin-settings-writes.php, shared with api/v1. The Director's alone
| (permissions.manage), from the browser only.
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

$p = currentPrincipal($pdo);
if (!$p || $p["via"] !== "session" || $p["kind"] !== "admin") {
    permissionsReply(false, "Unauthorized", [], 401);
}
if (!can($p, "permissions.manage", $pdo)) {
    permissionsReply(false, "Only the Director can change permissions.", [], 403);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    permissionsReply(true, "", ["data" => permissionsOverview($pdo)]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    permissionsReply(false, "Invalid request.", [], 405);
}

// Before the transaction: creating the tables would commit it.
ensurePermissionTables($pdo);

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$changes = $input["changes"] ?? null;

try {
    $pdo->beginTransaction();

    if (isset($input["role"])) {
        $role = (string) $input["role"];
        $clean = checkRolePermissionChange($role, $changes);
        setRolePermissions($pdo, $p, $role, $clean);
        $message = "Saved for every " . adminRoleName($role) . ".";
    } elseif (isset($input["admin_id"])) {
        [$target, $clean] = checkAdminPermissionChange($pdo, (int) $input["admin_id"], $changes);
        setAdminPermissions($pdo, $p, $target, $clean);
        $message = "Saved for " . $target["name"] . ".";
    } else {
        throw new PortalWriteError("Say which role or admin to change.");
    }

    $pdo->commit();
} catch (PortalWriteError $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    permissionsReply(false, $e->getMessage(), [], $e->status);
}

forgetPermissions();
permissionsReply(true, $message . " It applies on their next click - nobody is signed out.", ["data" => permissionsOverview($pdo)]);
