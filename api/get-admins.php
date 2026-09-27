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

if (!$admin) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}

if ($admin["role"] === "owner") {

    // The owner sees every Admin, Super Admin and Account Manager, plus
    // who each Admin has been distributed to (for the Distribute Admins panel).
    $stmt = $pdo->prepare("
        SELECT
            admins.id, admins.name, admins.email, admins.role, admins.active,
            admins.managed_by_admin_id,
            manager.name AS managed_by_name
        FROM admins
        LEFT JOIN admins manager ON manager.id = admins.managed_by_admin_id
        WHERE admins.role IN ('seo_admin', 'super_admin', 'account_manager')
        ORDER BY admins.id DESC
    ");

    $stmt->execute();

} else {

    // Available Admins on settings.html is the owner's alone.

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}

$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);


echo json_encode([
    "success" => true,
    "admins" => $admins
]);
