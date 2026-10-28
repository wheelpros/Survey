<?php
require_once "db.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ","",$headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token=? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch();

// Adding admins is an owner-only action - this now matches the gate
// settings.php / settings.html already use for this panel. (It previously
// required role === "super_admin", which silently blocked the owner.)
if(!$admin || $admin["role"] !== "owner"){
    echo json_encode(["success"=>false,"message"=>"Not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if(empty($input["password"])){
    echo json_encode(["success"=>false,"message"=>"Name, email, password and position are required"]);
    exit;
}

// Anything but an assignable role falls back to a plain Admin, as the
// dropdown's default.
$role = in_array($input["role"] ?? "", ASSIGNABLE_ADMIN_ROLES, true) ? $input["role"] : "seo_admin";

// The checks (valid email, not already an admin) are shared with api/v1's
// add_admin - admin-settings-writes.php.
try {
    [$name, $email] = checkNewAdmin($pdo, $input["name"] ?? "", $input["email"] ?? "", $role);
    $position = checkAdminPosition($input["position"] ?? "");
    createAdmin($pdo, $name, $email, $role, (string) $input["password"], $position);
} catch (PortalWriteError $e) {
    echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
    exit;
}

echo json_encode(["success"=>true]);
