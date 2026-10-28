<?php

/*
|--------------------------------------------------------------------------
| The Director card at the top of Settings
|--------------------------------------------------------------------------
|
| GET  api/update-my-profile.php   name, position, role, email, photo
| POST api/update-my-profile.php   JSON {name, position}
|
| The Director's own details. Only name and position change here - the email
| is what they sign in with and the role is fixed. Owner-only; shared checks
| with api/v1's update_my_profile (admin-settings-writes.php).
|
*/

require_once "db.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json; charset=UTF-8");

function reply($success, $message, $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$token = trim(str_ireplace("Bearer ", "", $headers["Authorization"] ?? $headers["authorization"] ?? $_SERVER["HTTP_AUTHORIZATION"] ?? ""));

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$admin = $token !== "" ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

if (!$admin || $admin["role"] !== "owner") {
    reply(false, "Only the Director has this card.", [], 403);
}

try {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        [$name, $position] = checkOwnProfile($input["name"] ?? "", $input["position"] ?? "");
        updateOwnProfile($pdo, $admin["id"], $name, $position);
        reply(true, "Your details were saved.", ["profile" => adminProfile($pdo, $admin["id"])]);
    }

    reply(true, "", ["profile" => adminProfile($pdo, $admin["id"])]);

} catch (PortalWriteError $e) {
    reply(false, $e->getMessage(), [], $e->status === 422 ? 400 : $e->status);
} catch (Throwable $e) {
    reply(false, "Could not load your details.", [], 500);
}
