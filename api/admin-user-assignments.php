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

// This one endpoint powers two different handoffs:
// - owner distributes approved users out to super_admins
// - each super_admin distributes their own assigned users out to seo_admins
// seo_admin is the bottom of the chain, so they get no assignment box and no
// access to this endpoint at all.
if (!in_array($role, ["owner", "super_admin"], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$targetRole = $role === "owner" ? "super_admin" : "seo_admin";

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $targetStmt = $pdo->prepare("
        SELECT id, name, email
        FROM admins
        WHERE role = ? AND active = 1
        ORDER BY name ASC
    ");
    $targetStmt->execute([$targetRole]);
    $targetAdmins = $targetStmt->fetchAll();

    if ($role === "owner") {
        // The owner distributes from every approved user.
        $users = $pdo->query("
            SELECT id, name, email
            FROM users
            WHERE approved = 1
            ORDER BY name ASC
        ")->fetchAll();
    } else {
        // A super_admin can only ever hand out users the owner already gave
        // them - their "pool" is their own current assignment, not everyone.
        $usersStmt = $pdo->prepare("
            SELECT u.id, u.name, u.email
            FROM users u
            INNER JOIN admin_user_assignments a ON a.user_id = u.id
            WHERE a.admin_id = ? AND u.approved = 1
            ORDER BY u.name ASC
        ");
        $usersStmt->execute([$myId]);
        $users = $usersStmt->fetchAll();
    }

    $ownUserIds = array_map(function ($u) { return (int)$u["id"]; }, $users);

    $assignments = [];

    if (!empty($targetAdmins)) {
        $targetIds = array_column($targetAdmins, "id");
        $placeholders = implode(",", array_fill(0, count($targetIds), "?"));

        $rowsStmt = $pdo->prepare("
            SELECT admin_id, user_id
            FROM admin_user_assignments
            WHERE admin_id IN ($placeholders)
        ");
        $rowsStmt->execute($targetIds);
        $rows = $rowsStmt->fetchAll();

        foreach ($rows as $row) {
            $userId = (int)$row["user_id"];

            // Inside a super_admin's own assignment box, only show the slice
            // of a seo_admin's assignments that came out of their own pool -
            // never a user handed out by a different super_admin.
            if ($role === "super_admin" && !in_array($userId, $ownUserIds, true)) {
                continue;
            }

            $adminId = $row["admin_id"];
            $assignments[$adminId][] = $userId;
        }
    }

    echo json_encode([
        "success" => true,
        "role" => $role,
        "targetRole" => $targetRole,
        "targetAdmins" => $targetAdmins,
        "users" => $users,
        "assignments" => $assignments
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $adminId = (int)($input["adminId"] ?? 0);
    $userIds = $input["userIds"] ?? [];
    $userIds = array_map("intval", $userIds);
    $userIds = array_values(array_unique(array_filter($userIds, function ($id) {
        return $id > 0;
    })));

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
        WHERE id = ? AND role = ? AND active = 1
        LIMIT 1
    ");
    $checkStmt->execute([$adminId, $targetRole]);

    if (!$checkStmt->fetch()) {
        $label = $targetRole === "super_admin" ? "Super admin" : "SEO admin";
        echo json_encode([
            "success" => false,
            "message" => "$label not found"
        ]);
        exit;
    }

    $ownUserIds = [];

    if ($role === "super_admin") {
        // Guard rail: a super_admin can only ever pass along users that were
        // themselves assigned to them - never someone else's user.
        $ownStmt = $pdo->prepare("SELECT user_id FROM admin_user_assignments WHERE admin_id = ?");
        $ownStmt->execute([$myId]);
        $ownUserIds = array_map("intval", array_column($ownStmt->fetchAll(), "user_id"));

        $userIds = array_values(array_intersect($userIds, $ownUserIds));
    }

    $pdo->beginTransaction();

    try {
        if ($role === "owner") {
            // Full replace: the owner is the single source of truth for who
            // a super_admin manages.
            $deleteStmt = $pdo->prepare("DELETE FROM admin_user_assignments WHERE admin_id = ?");
            $deleteStmt->execute([$adminId]);
        } elseif (!empty($ownUserIds)) {
            // Only clear the slice of this seo_admin's assignments that came
            // from the current super_admin's own pool - never touch rows
            // that belong to a different super_admin's users.
            $placeholders = implode(",", array_fill(0, count($ownUserIds), "?"));
            $deleteStmt = $pdo->prepare("
                DELETE FROM admin_user_assignments
                WHERE admin_id = ? AND user_id IN ($placeholders)
            ");
            $deleteStmt->execute(array_merge([$adminId], $ownUserIds));
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO admin_user_assignments (admin_id, user_id)
            VALUES (?, ?)
        ");

        foreach ($userIds as $userId) {
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
