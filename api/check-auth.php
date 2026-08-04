<?php

require_once "db.php";

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";

$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "No token provided"
    ]);
    exit;
}

ensureUserProfileColumns($pdo);

$stmt = $pdo->prepare("
    SELECT id, name, email, approved, profile_image,
           company_name, website, description, phone
    FROM users
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || (int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "user" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
        "profile_image" => $user["profile_image"],
        "company_name" => $user["company_name"],
        "website" => $user["website"],
        "description" => $user["description"],
        "phone" => $user["phone"]
    ]
]);