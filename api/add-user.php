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

// Multipart when the form carries a photo; JSON from older callers.
$input = !empty($_POST) ? $_POST : (json_decode(file_get_contents("php://input"), true) ?: []);

if(empty($input["password"])){
    echo json_encode(["success"=>false,"message"=>"Name, email, password and position are required"]);
    exit;
}

// Anything but an assignable role falls back to a plain Admin, as the
// dropdown's default.
$role = in_array($input["role"] ?? "", ASSIGNABLE_ADMIN_ROLES, true) ? $input["role"] : "seo_admin";

// The checks (valid email, not already an admin) are shared with api/v1's
// add_admin - admin-settings-writes.php. The photo is saved last, once
// everything else has passed, so a refused admin leaves no file behind.
$avatar = null;
try {
    [$name, $email] = checkNewAdmin($pdo, $input["name"] ?? "", $input["email"] ?? "", $role);
    $position = checkAdminPosition($input["position"] ?? "");
    $avatar = saveAdminAvatar($_FILES["avatar"] ?? null);
    createAdmin($pdo, $name, $email, $role, (string) $input["password"], $position, $avatar);
} catch (PortalWriteError $e) {
    deleteAdminAvatar($avatar);
    echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
    exit;
} catch (Throwable $e) {
    deleteAdminAvatar($avatar);
    echo json_encode(["success"=>false,"message"=>"Could not add the admin."]);
    exit;
}

echo json_encode(["success"=>true]);
