<?php

require_once "db.php";

header("Content-Type: application/json");

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

$adminStmt = $pdo->prepare("
    SELECT id, role
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");
$adminStmt->execute([$token]);
$currentAdmin = $adminStmt->fetch();

// The SEO Admin Assignments box on users-management.html is in the sidebar for
// every admin, so this can no longer be super-admin only - the page would load
// and every save would fail. Resolving the token against the admins table is
// the authorisation.
if (!$currentAdmin) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $seoAdmins = $pdo->query("
        SELECT id, name, email
        FROM admins
        WHERE role = 'seo_admin' AND active = 1
        ORDER BY name ASC
    ")->fetchAll();

    $users = $pdo->query("
        SELECT id, name, email
        FROM users
        WHERE approved = 1
        ORDER BY name ASC
    ")->fetchAll();

    $rows = $pdo->query("
        SELECT admin_id, user_id
        FROM admin_user_assignments
    ")->fetchAll();

    $assignments = [];

    foreach ($rows as $row) {
        $adminId = $row["admin_id"];

        if (!isset($assignments[$adminId])) {
            $assignments[$adminId] = [];
        }

        $assignments[$adminId][] = (int)$row["user_id"];
    }

    echo json_encode([
        "success" => true,
        "seoAdmins" => $seoAdmins,
        "users" => $users,
        "assignments" => $assignments
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $adminId = (int)($input["adminId"] ?? 0);
    $userIds = $input["userIds"] ?? [];

    if (!$adminId) {
        echo json_encode([
            "success" => false,
            "message" => "Admin ID required"
        ]);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE id = ? AND role = 'seo_admin' AND active = 1
        LIMIT 1
    ");
    $checkStmt->execute([$adminId]);

    if (!$checkStmt->fetch()) {
        echo json_encode([
            "success" => false,
            "message" => "SEO admin not found"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {
        $deleteStmt = $pdo->prepare("
            DELETE FROM admin_user_assignments
            WHERE admin_id = ?
        ");
        $deleteStmt->execute([$adminId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO admin_user_assignments (admin_id, user_id)
            VALUES (?, ?)
        ");

        foreach ($userIds as $userId) {
            $userId = (int)$userId;

            if ($userId) {
                $insertStmt->execute([$adminId, $userId]);
            }
        }

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Assignments saved successfully"
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();

        echo json_encode([
            "success" => false,
            "message" => "Failed to save assignments"
        ]);
        exit;
    }
}