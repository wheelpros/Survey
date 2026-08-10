<?php

require_once "db.php";

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

// The token has to resolve to a real admin. This endpoint approves and deletes
// users, and it used to accept any non-empty string as authorisation.
$adminStmt = $pdo->prepare("SELECT id FROM admins WHERE session_token = ? LIMIT 1");
$adminStmt->execute([$token]);

if (!$adminStmt->fetch()) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";
$userId = (int)($input["userId"] ?? 0);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!$userId) {
        echo json_encode([
            "success" => false,
            "message" => "User ID required"
        ]);
        exit;
    }

    if ($action === "approve") {
        $stmt = $pdo->prepare("UPDATE users SET approved = 1 WHERE id = ?");
        $stmt->execute([$userId]);

        echo json_encode([
            "success" => true,
            "message" => "User approved successfully"
        ]);
        exit;
    }

    if ($action === "reject") {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        echo json_encode([
            "success" => true,
            "message" => "User rejected successfully"
        ]);
        exit;
    }

    echo json_encode([
        "success" => false,
        "message" => "Invalid action"
    ]);
    exit;
}

// company_name backs the Client dropdown on admin-content-form.html.
ensureUserProfileColumns($pdo);

$stmt = $pdo->query("
    SELECT id, name, email, company_name, approved, created_at
    FROM users
    ORDER BY created_at DESC
");

$users = $stmt->fetchAll();

echo json_encode([
    "success" => true,
    "users" => $users
]);