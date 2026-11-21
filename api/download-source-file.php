<?php
require_once "db.php";
require_once "auth.php";

$token = $_GET["token"] ?? "";
$fileId = (int)($_GET["id"] ?? 0);

// A link the browser opens, so the session comes in ?token=. An active admin
// with the Files page, and only their own files (below).
$admin = adminFromSessionToken($pdo, $token);

if(!$admin){
  http_response_code(401);
  die("Unauthorized");
}

if(!can($admin, "pages.files", $pdo)){
  http_response_code(403);
  die("The Director has turned this page off for you.");
}

$stmt = $pdo->prepare("
  SELECT original_name,file_path,file_type
  FROM source_files
  WHERE id=? AND admin_id=?
");
$stmt->execute([$fileId, $admin["id"]]);
$file = $stmt->fetch();

if(!$file || !file_exists($file["file_path"])){
  http_response_code(404);
  die("File not found");
}

header("Content-Type: " . ($file["file_type"] ?: "application/octet-stream"));
header("Content-Disposition: attachment; filename=\"" . basename($file["original_name"]) . "\"");
header("Content-Length: " . filesize($file["file_path"]));

readfile($file["file_path"]);
exit;