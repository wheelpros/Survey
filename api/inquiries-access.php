<?php

/*
| The account managers' inquiries access, the way inquiries.html has always
| set it. It is the inquiries.read permission now: a tick that matches what
| the role already gets leaves no override behind, so a later change to the
| role still reaches them.
*/

require_once "db.php";
require_once "auth.php";
require_once "events.php";

header("Content-Type: application/json; charset=UTF-8");

ensureInquiryTables($pdo);
ensurePermissionTables($pdo);

$currentAdmin = requireAdmin($pdo, "permissions.manage", "Unauthorized");

$method = $_SERVER["REQUEST_METHOD"];

$managers = $pdo->query("
    SELECT id, name, email, role
    FROM admins
    WHERE role = 'account_manager'
    ORDER BY name ASC
")->fetchAll();

if ($method === "GET") {

    echo json_encode([
        "success" => true,
        "accountManagers" => array_map(function ($m) use ($pdo) {
            return [
                "id" => $m["id"],
                "name" => $m["name"],
                "email" => $m["email"],
                "inquiries_access" => can($m, "inquiries.read", $pdo) ? 1 : 0,
            ];
        }, $managers),
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $grantedIds = $input["accountManagerIds"] ?? [];
    $grantedIds = array_map("intval", is_array($grantedIds) ? $grantedIds : []);

    // Full replace, same pattern as Owner Assignments: whoever's checked
    // gets access, everyone else with this role loses it.
    $roleGets = rolePermissionMatrix($pdo)["account_manager"]["inquiries.read"];
    foreach ($managers as $m) {
        // The Permissions panel's rules: never your own row, and only the
        // Director changes someone holding a Director-only key.
        if (adminTargetRefusal($currentAdmin, $m, "permissions", $pdo) !== null) {
            continue;
        }
        $granted = in_array((int) $m["id"], $grantedIds, true);
        storeAdminPermission($pdo, $m["id"], "inquiries.read", $granted === $roleGets ? null : $granted, $currentAdmin["id"]);
    }

    $names = eventAdminNames($pdo, $grantedIds);
    logEvent($pdo, EVENT_INQUIRY_ACCESS, "settings", 0, "Inquiries access",
        $names !== "" ? "Gave inquiries access to " . $names : "Took inquiries access from every account manager");

    echo json_encode(["success" => true, "message" => "Inquiries access updated"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
