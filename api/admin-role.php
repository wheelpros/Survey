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

if (($caller["role"] ?? "") !== "owner") {
    roleReply(false, "Only the owner can change roles.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];

$targetId = (int) ($input["admin_id"] ?? 0);
$newRole = trim((string) ($input["role"] ?? ""));

$assignable = ["seo_admin", "super_admin", "account_manager"];

if (!$targetId || !in_array($newRole, $assignable, true)) {
    roleReply(false, "Invalid request.");
}

$stmt = $pdo->prepare("
    SELECT id, name, email, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$targetId]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    roleReply(false, "Admin not found.");
}

if ($target["role"] === "owner") {
    roleReply(false, "The owner's role cannot be changed here.");
}

// Belt and braces: an owner editing their own row would already have been
// caught above, but the intent is worth stating.
if ((int) $target["id"] === (int) $caller["id"]) {
    roleReply(false, "You cannot change your own role.");
}

if ($target["role"] === $newRole) {
    roleReply(true, "No change - that is already their role.");
}

/*
| session_token is cleared along with the role.
|
| Every page reads the role from the copy of the admin record saved in the
| browser at sign-in, so someone already logged in would keep their old
| permissions in the interface until they happened to sign in again. Ending
| the session forces that, and the next login hands them the new role.
*/

$update = $pdo->prepare("
    UPDATE admins
    SET role = ?,
        session_token = NULL
    WHERE id = ?
");

$update->execute([$newRole, $targetId]);

$labels = [
    "seo_admin"       => "Admin",
    "super_admin"     => "Super Admin",
    "account_manager" => "Account Manager"
];

roleReply(true, $target["name"] . " is now " . $labels[$newRole] . ". They will be signed out and pick up the change on their next login.", [
    "admin_id" => $targetId,
    "role"     => $newRole
]);
