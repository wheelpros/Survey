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

$user = clientFromSessionToken($pdo, $token);

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
        "phone" => $user["phone"],
        "whatsapp" => $user["whatsapp"],
        "is_account_owner" => $user["is_account_owner"]
    ]
]);