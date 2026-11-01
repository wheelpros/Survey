<?php

require_once "db.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

// Only the owner distributes Admin accounts to a Super Admin / Account Manager.
if (!$currentAdmin || !can($currentAdmin, "admins.manage", $pdo)) {
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
        SELECT id, name, email, role
        FROM admins
        WHERE role = 'seo_admin'
        ORDER BY name ASC
    ");
    $admins = $poolStmt->fetchAll();

    // Current assignments, keyed by manager id -> array of admin ids. Super
    // admins are in here too: an account manager can be handed super admins
    // as well as plain admins (see settings.html distributePoolFor()).
    $assignmentsStmt = $pdo->query("
        SELECT id, managed_by_admin_id
        FROM admins
        WHERE role IN ('seo_admin', 'super_admin') AND managed_by_admin_id IS NOT NULL
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

    // Who may sit under which manager, and the full-replace save, are shared
    // with api/v1 (admin-settings-writes.php). Ids from outside the pool
    // settings.html offers are quietly dropped.
    try {
        [, $adminIds, $allowedRoles] = checkManagerAssignment($pdo, $managerId, $adminIds);
        assignAdminsToManager($pdo, $managerId, $adminIds, $allowedRoles);
    } catch (PortalWriteError $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Assignments saved successfully"
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);
