<?php
require_once "db.php";

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

$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");

if(!$name || !$email || empty($input["password"])){
    echo json_encode(["success"=>false,"message"=>"Name, email and password are required"]);
    exit;
}

$password = password_hash($input["password"], PASSWORD_DEFAULT);

// Whitelist every admin role that actually exists now - previously this
// only recognized super_admin/seo_admin and silently downgraded anything
// else (including account_manager) to seo_admin.
$allowedRoles = ["seo_admin", "super_admin", "account_manager"];
$role = in_array($input["role"] ?? "", $allowedRoles, true) ? $input["role"] : "seo_admin";

$stmt = $pdo->prepare("
    INSERT INTO admins (name,email,password,role,active)
    VALUES (?,?,?,?,1)
");

$stmt->execute([$name,$email,$password,$role]);

echo json_encode(["success"=>true]);
