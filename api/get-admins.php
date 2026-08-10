<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();

$token = str_replace(
    "Bearer ",
    "",
    $headers["Authorization"] ?? ""
);


// Check logged-in admin
$stmt = $pdo->prepare("
    SELECT id, role
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);

$admin = $stmt->fetch(PDO::FETCH_ASSOC);


// Only owner
if (!$admin || $admin["role"] !== "owner") {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}


// Get SEO Admins + Super Admins
$stmt = $pdo->prepare("
    SELECT id, name, email, role
    FROM admins
    WHERE role IN ('seo_admin', 'super_admin')
    ORDER BY id DESC
");

$stmt->execute();

$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);


echo json_encode([
    "success" => true,
    "admins" => $admins
]);