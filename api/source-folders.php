<?php
require_once "db.php";
require_once "sources-lock.php";
header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id FROM admins WHERE session_token=? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if(!$admin){
  echo json_encode(["success"=>false,"message"=>"Unauthorized"]);
  exit;
}

/*
 An admin session gets you this far; the Files password gets you the
 rest. Without this the password would only be hiding the page - the folders
 would still come back to anyone holding a session token.
*/
requireSourcesUnlock($pdo, $admin["id"]);

$method = $_SERVER["REQUEST_METHOD"];

if($method === "GET"){
  $stmt = $pdo->prepare("
    SELECT id,title,created_at
    FROM source_folders
    WHERE admin_id=?
    ORDER BY created_at DESC
  ");
  $stmt->execute([$admin["id"]]);

  echo json_encode([
    "success"=>true,
    "folders"=>$stmt->fetchAll()
  ]);
  exit;
}

if($method === "POST"){
  $input = json_decode(file_get_contents("php://input"), true);
  $title = trim($input["title"] ?? "");

  if(!$title){
    echo json_encode(["success"=>false,"message"=>"Folder title required"]);
    exit;
  }

  $stmt = $pdo->prepare("
    INSERT INTO source_folders (admin_id,title)
    VALUES (?,?)
  ");
  $stmt->execute([$admin["id"], $title]);

  echo json_encode(["success"=>true,"message"=>"Folder created"]);
  exit;
}