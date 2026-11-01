<?php

require_once "db.php";
require_once "admin-settings-writes.php";

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

if (!$admin) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}

// Available Admins on settings.html is the owner's alone.
if (!can($admin, "admins.manage", $pdo)) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}

// Every Admin, Super Admin and Account Manager, plus who each Admin has been
// distributed to - the same list api/v1's get_settings returns.
$admins = listTeamAdmins($pdo);


echo json_encode([
    "success" => true,
    "admins" => $admins
]);
