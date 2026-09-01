<?php

/*
|--------------------------------------------------------------------------
| Is this admin session still good?
|--------------------------------------------------------------------------
|
| Cheap enough to poll. The pages call it every so often so an admin whose
| role was just changed - or who was deactivated - does not carry on with the
| old permissions in a tab that was already open.
|
| Changing a role clears session_token (see admin-role.php), so the row stops
| matching and this answers false. The page then clears its storage and sends
| them back to the login screen, where the fresh role is handed out.
|
| The current role goes back in the reply too, so a page can spot a stale copy
| in localStorage even in the cases where the session itself survived.
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    echo json_encode(["success" => false, "valid" => false]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email, role, active
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// A deactivated account counts as signed out, the same as a cleared token.
if (!$admin || (int) $admin["active"] !== 1) {
    echo json_encode(["success" => true, "valid" => false]);
    exit;
}

echo json_encode([
    "success" => true,
    "valid"   => true,
    "admin"   => [
        "id"    => (int) $admin["id"],
        "name"  => $admin["name"],
        "email" => $admin["email"],
        "role"  => $admin["role"]
    ]
]);
