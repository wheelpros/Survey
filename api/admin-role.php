<?php

/*
|--------------------------------------------------------------------------
| Change an admin's role
|--------------------------------------------------------------------------
|
| Promotes or demotes an existing admin between the three assignable roles:
|
|   seo_admin        shown as "Admin"
|   super_admin
|   account_manager
|
| `owner` is not on that list. It is not something to hand out from a
| dropdown, and an owner row cannot be demoted here either - otherwise the
| last owner could be removed and nobody would be able to put one back.
|
| Only an owner may call this. The role decides what every other endpoint
| lets a person do, so being able to edit it is being able to grant yourself
| anything.
|
*/

require_once "db.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json; charset=UTF-8");

function roleReply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    roleReply(false, "Unauthorized");
}

$stmt = $pdo->prepare("
    SELECT id, role
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);
$caller = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caller) {
    roleReply(false, "Unauthorized");
}

if (!can($caller, "admins.manage", $pdo)) {
    roleReply(false, "Only the Director can change roles.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];

$targetId = (int) ($input["admin_id"] ?? 0);
$newRole = trim((string) ($input["role"] ?? ""));

if (!$targetId || !in_array($newRole, ASSIGNABLE_ADMIN_ROLES, true)) {
    roleReply(false, "Invalid request.");
}

// The rules (never an owner row, never yourself) and the session reset that
// goes with a change are in admin-settings-writes.php, shared with api/v1.
try {
    $target = checkAdminRoleChange($pdo, $caller, $targetId, $newRole);

    if ($target["role"] === $newRole) {
        roleReply(true, "No change - that is already their role.");
    }

    changeAdminRole($pdo, $targetId, $newRole);
} catch (PortalWriteError $e) {
    roleReply(false, $e->getMessage());
}

roleReply(true, $target["name"] . " is now " . adminRoleName($newRole) . ". They will be signed out and pick up the change on their next login.", [
    "admin_id" => $targetId,
    "role"     => $newRole
]);
