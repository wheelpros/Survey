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


// Get Admins ("seo_admin"), Super Admins and Account Managers.
// `active` is included so the Available Admins list can actually show
// whether each admin is active/inactive and render the right button.
$stmt = $pdo->prepare("
    SELECT id, name, email, role, active
    FROM admins
    WHERE role IN ('seo_admin', 'super_admin', 'account_manager')
    ORDER BY id DESC
");

$stmt->execute();

$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);


echo json_encode([
    "success" => true,
    "admins" => $admins
]);
