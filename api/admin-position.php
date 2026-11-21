<?php

/*
|--------------------------------------------------------------------------
| Change an admin's position
|--------------------------------------------------------------------------
|
| The position is what the sidebar and the account popup show in place of an
| admin's name, e.g. "SEO Specialist". The owner sets it when adding someone
| (add-user.php) and changes it here, for anyone - their own included.
|
| Unlike a role change, nobody is signed out: it is only a label, and the
| pages pick it up on their next session check (assets/role-nav.js).
|
| Only the owner may call this.
|
*/

require_once "db.php";
require_once "auth.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json; charset=UTF-8");

function positionReply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$caller = requireAdmin($pdo, "admins.manage", "Only the Director can change positions.");

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$targetId = (int) ($input["admin_id"] ?? 0);

if (!$targetId) {
    positionReply(false, "Invalid request.");
}

try {
    $target = findAdminRow($pdo, $targetId);
    if ((int) $target["id"] !== (int) $caller["id"]) {
        checkAdminTarget($pdo, $caller, $target, "edit");
    }
    $position = checkAdminPosition($input["position"] ?? "");
    setAdminPosition($pdo, $targetId, $position);
} catch (PortalWriteError $e) {
    positionReply(false, $e->getMessage());
}

positionReply(true, $target["name"] . " is now " . $position . ".", [
    "admin_id" => $targetId,
    "position" => $position
]);
