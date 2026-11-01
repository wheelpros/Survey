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

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    positionReply(false, "Unauthorized");
}

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$caller = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caller) {
    positionReply(false, "Unauthorized");
}

if (!can($caller, "admins.manage", $pdo)) {
    positionReply(false, "Only the owner can change positions.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$targetId = (int) ($input["admin_id"] ?? 0);

if (!$targetId) {
    positionReply(false, "Invalid request.");
}

try {
    $target = findAdminRow($pdo, $targetId);
    $position = checkAdminPosition($input["position"] ?? "");
    setAdminPosition($pdo, $targetId, $position);
} catch (PortalWriteError $e) {
    positionReply(false, $e->getMessage());
}

positionReply(true, $target["name"] . " is now " . $position . ".", [
    "admin_id" => $targetId,
    "position" => $position
]);
