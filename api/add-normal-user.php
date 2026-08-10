<?php
require_once "db.php";

$input = json_decode(file_get_contents("php://input"), true);

$name = $input["name"];
$email = $input["email"];
$password = password_hash($input["password"], PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (name,email,password)
    VALUES (?,?,?)
");

$stmt->execute([$name,$email,$password]);

echo json_encode(["success"=>true]);
