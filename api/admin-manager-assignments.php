<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

// Only the owner distributes Admin accounts to a Super Admin / Account Manager.
if (!$currentAdmin || $currentAdmin["role"] !== "owner") {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    // Who a plain Admin can be handed off to.
    $managersStmt = $pdo->query("
        SELECT id, name, email, role
        FROM admins
        WHERE role IN ('super_admin', 'account_manager')
        ORDER BY name ASC
    ");
    $targetAdmins = $managersStmt->fetchAll();

    // The pool of Admin accounts available to hand off.
    $poolStmt = $pdo->query("
        SELECT id, name, email
        FROM admins
        WHERE role = 'seo_admin'
        ORDER BY name ASC
    ");
    $admins = $poolStmt->fetchAll();

    // Current assignments, keyed by manager id -> array of admin ids,
    // same shape admin-user-assignments.php uses for its assignments map.
    $assignmentsStmt = $pdo->query("
        SELECT id, managed_by_admin_id
        FROM admins
        WHERE role = 'seo_admin' AND managed_by_admin_id IS NOT NULL
    ");

    $assignments = [];
    foreach ($assignmentsStmt->fetchAll() as $row) {
        $managerId = (string)$row["managed_by_admin_id"];
        if (!isset($assignments[$managerId])) {
            $assignments[$managerId] = [];
        }
        $assignments[$managerId][] = (int)$row["id"];
    }

    echo json_encode([
        "success" => true,
        "targetAdmins" => $targetAdmins,
        "admins" => $admins,
        "assignments" => $assignments
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $managerId = (int)($input["managerId"] ?? 0);
    $adminIds = $input["adminIds"] ?? [];
    $adminIds = array_map("intval", is_array($adminIds) ? $adminIds : []);

    if (!$managerId) {
        echo json_encode([
            "success" => false,
            "message" => "Please make a selection."
        ]);
        exit;
    }

    $managerStmt = $pdo->prepare("SELECT id, role FROM admins WHERE id = ? LIMIT 1");
    $managerStmt->execute([$managerId]);
    $manager = $managerStmt->fetch();

    if (!$manager || !in_array($manager["role"], ["super_admin", "account_manager"], true)) {
        echo json_encode([
            "success" => false,
            "message" => "That manager was not found."
        ]);
        exit;
    }

    // Only real Admin (seo_admin) ids are ever accepted here - this also
    // quietly drops anything bogus sent from outside the checkbox list.
    $validIdsStmt = $pdo->query("SELECT id FROM admins WHERE role = 'seo_admin'");
    $validAdminIds = array_map(function ($row) {
        return (int)$row["id"];
    }, $validIdsStmt->fetchAll());

    $adminIds = array_values(array_intersect($adminIds, $validAdminIds));

    $pdo->beginTransaction();

    try {
        // Full replace, same as admin-user-assignments.php: clear whatever
        // this manager currently has, then set it to exactly what was checked.
        // (An admin checked here that belonged to a different manager moves
        // over - that's intended, since one Admin only ever has one manager.)
        $clearStmt = $pdo->prepare("
            UPDATE admins
            SET managed_by_admin_id = NULL
            WHERE managed_by_admin_id = ?
        ");
        $clearStmt->execute([$managerId]);

        if (!empty($adminIds)) {
            $placeholders = implode(",", array_fill(0, count($adminIds), "?"));

            $assignStmt = $pdo->prepare("
                UPDATE admins
                SET managed_by_admin_id = ?
                WHERE id IN ($placeholders) AND role = 'seo_admin'
            ");
            $assignStmt->execute(array_merge([$managerId], $adminIds));
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

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);
