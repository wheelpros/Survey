<?php
require_once "db.php";

$headers = getallheaders();
$token = str_replace("Bearer ","",$headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token=? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if(!$admin || $admin["role"] !== "super_admin"){
    echo json_encode(["success"=>false,"message"=>"Not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

$name = $input["name"];
$email = $input["email"];
$password = password_hash($input["password"], PASSWORD_DEFAULT);
$role = $input["role"] === "super_admin" ? "super_admin" : "seo_admin";

$stmt = $pdo->prepare("
    INSERT INTO admins (name,email,password,role,active)
    VALUES (?,?,?,?,1)
");

$stmt->execute([$name,$email,$password,$role]);

echo json_encode(["success"=>true]);