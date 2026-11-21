<?php

require_once "db.php";
require_once "auth.php";
require_once "events.php";

header("Content-Type: application/json");

// Every admin reaches this list (Clients Management, and the client pickers
// on the post form and Files); what differs is the scope of the list, decided
// further down.
$currentAdmin = requireAdmin($pdo);

$role = $currentAdmin["role"];
$myId = (int)$currentAdmin["id"];

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {

    // Approving / rejecting a registration is the owner's call only. Every
    // other role on this page gets a View button and nothing more.
    if (!can($currentAdmin, "clients.approve", $pdo)) {
        echo json_encode([
            "success" => false,
            "message" => "Only the Director can approve or reject users"
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

    // A teammate joined through their owner's invite: there is nothing to decide.
    $isTeammate = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND account_owner_id IS NOT NULL LIMIT 1");
    $isTeammate->execute([$userId]);
    if ($isTeammate->fetchColumn()) {
        echo json_encode(["success" => false, "message" => "That person is on a client's team, not a registration."]);
        exit;
    }

    if ($action === "approve") {
        $stmt = $pdo->prepare("UPDATE users SET approved = 1 WHERE id = ?");
        $stmt->execute([$userId]);

        $who = eventClientName($pdo, $userId);
        logEvent($pdo, EVENT_CLIENT_APPROVED, "user", $userId, $who, "Approved " . $who . "'s account", $userId);

        echo json_encode([
            "success" => true,
            "message" => "User approved successfully"
        ]);
        exit;
    }

    if ($action === "reject") {
        $who = eventClientName($pdo, $userId);

        /* Rejecting is for a sign-up still waiting. An approved client has
           posts, files and a team; removing one is clients.delete, through
           delete-client.php and its safeguards (audit S10). */
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND approved = 0 AND account_owner_id IS NULL");
        $stmt->execute([$userId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Only a sign-up that is still waiting can be rejected. To remove an approved client, delete them."
            ]);
            exit;
        }

        logEvent($pdo, EVENT_CLIENT_REJECTED, "user", $userId, $who, "Rejected " . $who . "'s sign-up", $userId);

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
// - account_manager likewise: the owner assigns clients to them directly
// - seo_admin sees only the users their super_admin assigned to them
//
// Everyone except the owner reads from the same assignment table, so the one
// query below covers all three.
if (can($currentAdmin, "clients.view_all", $pdo)) {

    $stmt = $pdo->query("
        SELECT id, name, email, company_name, approved, created_at
        FROM users
        WHERE account_owner_id IS NULL
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll();

} else {

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name, u.approved, u.created_at
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ? AND u.account_owner_id IS NULL
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
