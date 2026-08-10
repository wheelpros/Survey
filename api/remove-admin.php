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


// Only owner can remove admins
if (!$admin || $admin["role"] !== "owner") {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}


// Get ID
$input = json_decode(
    file_get_contents("php://input"),
    true
);

$id = intval($input["id"] ?? 0);

if (!$id) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid admin ID"
    ]);

    exit;
}


// Make sure target is NOT owner
$stmt = $pdo->prepare("
    SELECT id, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$target = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target) {

    echo json_encode([
        "success" => false,
        "message" => "Admin not found"
    ]);

    exit;
}


if ($target["role"] === "owner") {

    echo json_encode([
        "success" => false,
        "message" => "You cannot remove the owner"
    ]);

    exit;
}


// Remove only SEO Admin / Super Admin
if (!in_array($target["role"], ["seo_admin", "super_admin"], true)) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid admin role"
    ]);

    exit;
}


// Delete from database
$stmt = $pdo->prepare("
    DELETE FROM admins
    WHERE id = ?
");

$stmt->execute([$id]);


echo json_encode([
    "success" => true,
    "message" => "Admin removed successfully"
]);