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

// The token has to resolve to a real admin, and this page is only ever shown
// to owner / super_admin / seo_admin - anything else has no business here.
$adminStmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$adminStmt->execute([$token]);
$currentAdmin = $adminStmt->fetch();

if (!$currentAdmin) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$role = $currentAdmin["role"];
$myId = (int)$currentAdmin["id"];

if (!in_array($role, ["owner", "super_admin", "seo_admin"], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {

    // Approving / rejecting a registration is the owner's call only. Every
    // other role on this page gets a View button and nothing more.
    if ($role !== "owner") {
        echo json_encode([
            "success" => false,
            "message" => "Only the owner can approve or reject users"
        ]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input["action"] ?? "";
    $userId = (int)($input["userId"] ?? 0);

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

        // A deleted user can't stay handed out to a super_admin / seo_admin.
        $cleanupStmt = $pdo->prepare("DELETE FROM admin_user_assignments WHERE user_id = ?");
        $cleanupStmt->execute([$userId]);

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

// GET - scoped by role:
// - owner sees every registered user (pending + approved)
// - super_admin sees only the users the owner assigned to them
// - seo_admin sees only the users their super_admin assigned to them
if ($role === "owner") {

    $stmt = $pdo->query("
        SELECT id, name, email, company_name, approved, created_at
        FROM users
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll();

} else {

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name, u.approved, u.created_at
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ?
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$myId]);
    $users = $stmt->fetchAll();
}

echo json_encode([
    "success" => true,
    "role" => $role,
    "users" => $users
]);
