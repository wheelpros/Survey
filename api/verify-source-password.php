<?php
require_once "db.php";
require_once "auth.php";
header("Content-Type: application/json");

$caller=requireAdmin($pdo);
requirePage($pdo, "files");

$stmt=$pdo->prepare("SELECT id,sources_password FROM admins WHERE id=?");
$stmt->execute([$caller["id"]]);

$admin=$stmt->fetch();

if(!$admin){

    echo json_encode([
        "success"=>false
    ]);

    exit;
}

$input=json_decode(file_get_contents("php://input"),true);

$password=$input["password"]??"";

if(password_verify($password,$admin["sources_password"])){

    echo json_encode([
        "success"=>true
    ]);

}else{

    echo json_encode([
        "success"=>false,
        "message"=>"Wrong password"
    ]);

}