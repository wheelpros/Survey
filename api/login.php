<?php

require_once "db.php";
require_once "rate-limit.php";

// Every attempt counts, right password or wrong, so guessing runs out here.
if (rateLimited($pdo, "login", 20, 20, 300)) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "message" => "Too many attempts. Please wait a few minutes and try again."
    ]);
    exit;
}

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

ensureUserProfileColumns($pdo);

$stmt = $pdo->prepare("
    SELECT id, name, email, password, approved, profile_image,
           company_name, website, description, phone, account_owner_id
    FROM users
    WHERE email = ?
    LIMIT 1
");

$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password"])) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

if ((int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Your account is pending approval."
    ]);
    exit;
}

$token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare("
    UPDATE users 
    SET session_token = ? 
    WHERE id = ?
");

$stmt->execute([$token, $user["id"]]);

// A teammate's company details are their account's.
$user = withClientAccount($pdo, $user);

if (!$user) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "token" => $token,
    "user" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
        "profile_image" => $user["profile_image"],
        "company_name" => $user["company_name"],
        "website" => $user["website"],
        "description" => $user["description"],
        "phone" => $user["phone"],
        "is_account_owner" => $user["is_account_owner"]
    ]
]);