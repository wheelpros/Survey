<?php

require_once "db.php";

header("Content-Type: application/json");

ensureInquiryTables($pdo);

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

if (!$currentAdmin || $currentAdmin["role"] !== "owner") {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $stmt = $pdo->query("
        SELECT id, name, email, inquiries_access
        FROM admins
        WHERE role = 'account_manager'
        ORDER BY name ASC
    ");

    echo json_encode([
        "success" => true,
        "accountManagers" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $grantedIds = $input["accountManagerIds"] ?? [];
    $grantedIds = array_map("intval", is_array($grantedIds) ? $grantedIds : []);

    // Full replace, same pattern as Owner Assignments: whoever's checked
    // gets access, everyone else with this role loses it.
    $pdo->prepare("UPDATE admins SET inquiries_access = 0 WHERE role = 'account_manager'")->execute();

    if (!empty($grantedIds)) {
        $placeholders = implode(",", array_fill(0, count($grantedIds), "?"));
        $stmt = $pdo->prepare("
            UPDATE admins
            SET inquiries_access = 1
            WHERE role = 'account_manager' AND id IN ($placeholders)
        ");
        $stmt->execute($grantedIds);
    }

    echo json_encode(["success" => true, "message" => "Inquiries access updated"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
