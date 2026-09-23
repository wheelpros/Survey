<?php

require_once "db.php";

$input = json_decode(file_get_contents("php://input"), true);

$name = trim($input["name"] ?? "");
$email = strtolower(trim($input["email"] ?? ""));
$company = trim($input["company"] ?? "");
$password = $input["password"] ?? "";

// The company is how admins tell clients apart on every list, so an account
// is never created without one.
if (!$name || !$email || !$company || !$password) {
    echo json_encode([
        "success" => false,
        "message" => "Name, email, company name and password are required"
    ]);
    exit;
}

if (mb_strlen($company) > 150) {
    echo json_encode([
        "success" => false,
        "message" => "Company name must be 150 characters or fewer"
    ]);
    exit;
}

ensureUserProfileColumns($pdo);

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);

if ($stmt->fetch()) {
    echo json_encode([
        "success" => false,
        "message" => "Email already exists"
    ]);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (name, email, company_name, password, approved)
    VALUES (?, ?, ?, ?, 0)
");

$stmt->execute([
    $name,
    $email,
    $company,
    $passwordHash
]);

echo json_encode([
    "success" => true,
    "message" => "Account created. Please wait for admin approval."
]);