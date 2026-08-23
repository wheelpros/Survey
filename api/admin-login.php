<?php

require_once "db.php";

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

$email = strtolower(trim($input["email"] ?? ""));
$password = $input["password"] ?? "";

if (!$email || !$password) {
    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email, password, role, active
    FROM admins
    WHERE LOWER(email) = ?
    LIMIT 1
");

$stmt->execute([$email]);
$admin = $stmt->fetch();

if (!$admin) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

if (!password_verify($password, $admin["password"])) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

// Correct credentials, but the account has been deactivated - tell them
// that directly instead of the generic wrong-credentials message.
if ((int)$admin["active"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "This account has been deactivated. Contact the owner for access."
    ]);
    exit;
}

$token = bin2hex(random_bytes(32));

$updateStmt = $pdo->prepare("
    UPDATE admins
    SET session_token = ?
    WHERE id = ?
");

$updateStmt->execute([
    $token,
    $admin["id"]
]);

echo json_encode([
    "success" => true,
    "message" => "Admin login successful",
    "token" => $token,
    "admin" => [
        "id" => $admin["id"],
        "name" => $admin["name"],
        "email" => $admin["email"],
        "role" => $admin["role"]
    ]
]);
