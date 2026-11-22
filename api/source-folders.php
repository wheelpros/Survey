<?php
require_once "db.php";
require_once "auth.php";
requirePage($pdo, "files"); // "Open X" in Settings > Permissions
require_once "sources-lock.php";
require_once "events.php";
header("Content-Type: application/json");

$admin = requireAdmin($pdo);

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

  logEvent($pdo, EVENT_SOURCE_CHANGED, "source_folder", $pdo->lastInsertId(), $title, "Created source folder \"" . $title . "\"");

  echo json_encode(["success"=>true,"message"=>"Folder created"]);
  exit;
}