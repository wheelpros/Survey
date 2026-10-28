<?php

/*
|--------------------------------------------------------------------------
| Edit an admin
|--------------------------------------------------------------------------
|
| What the Edit popup on Settings saves: name, email, position and role, all
| at once. Changing the role signs the person out (it is what every other
| endpoint reads to decide what they may do); the rest is only labels and
| contact details, and signs nobody out.
|
| Only the owner may call this, and never for the owner's own row.
|
| The checks are checkAdminUpdate() in admin-settings-writes.php; the
| position alone, and the role alone, have their own endpoints for the callers
| that change only one (admin-position.php, admin-role.php).
|
*/

require_once "db.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json; charset=UTF-8");

function updateReply($success, $message = "", $extra = [])
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
    updateReply(false, "Unauthorized");
}

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$caller = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caller) {
    updateReply(false, "Unauthorized");
}

if (($caller["role"] ?? "") !== "owner") {
    updateReply(false, "Only the owner can edit admins.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$targetId = (int) ($input["admin_id"] ?? 0);

if (!$targetId) {
    updateReply(false, "Invalid request.");
}

try {
    [$target, $clean] = checkAdminUpdate($pdo, $caller, $targetId, $input);
    $signedOut = updateAdmin($pdo, $target, $clean);
} catch (PortalWriteError $e) {
    updateReply(false, $e->getMessage());
}

updateReply(
    true,
    $clean["name"] . " was updated." . ($signedOut ? " They will be signed out and pick up the new role on their next login." : ""),
    ["admin" => ["id" => $targetId] + $clean, "signed_out" => $signedOut]
);
