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
 Same gate as source-folders.php: the admin session is only half of it. The
 files themselves are what the password is protecting, so the check belongs
 here rather than only on the page that lists them.
*/
requireSourcesUnlock($pdo, $admin["id"]);

$uploadRoot = __DIR__ . "/../uploads/sources";

if(!is_dir($uploadRoot)){
  mkdir($uploadRoot, 0777, true);
}

$method = $_SERVER["REQUEST_METHOD"];

if($method === "GET"){
  $folderId = (int)($_GET["folder_id"] ?? 0);

  $check = $pdo->prepare("
    SELECT id FROM source_folders
    WHERE id=? AND admin_id=?
  ");
  $check->execute([$folderId, $admin["id"]]);

  if(!$check->fetch()){
    echo json_encode(["success"=>false,"message"=>"Folder not found"]);
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT
        sf.id,
        sf.original_name,
        sf.file_type,
        sf.file_size,
        sf.uploaded_at,

        u.id    AS assigned_user_id,
        u.name  AS assigned_user_name,
        u.email AS assigned_user_email

    FROM source_files sf

    LEFT JOIN user_source_files usf
        ON sf.id = usf.file_id

    LEFT JOIN users u
        ON usf.user_id = u.id

    WHERE sf.folder_id = ?
      AND sf.admin_id = ?

    ORDER BY sf.uploaded_at DESC
");

$stmt->execute([
    $folderId,
    $admin["id"]
]);

  echo json_encode([
    "success"=>true,
    "files"=>$stmt->fetchAll()
  ]);
  exit;
}

if($method === "POST"){
  $folderId = (int)($_POST["folder_id"] ?? 0);

  $check = $pdo->prepare("
    SELECT id FROM source_folders
    WHERE id=? AND admin_id=?
  ");
  $check->execute([$folderId, $admin["id"]]);

  if(!$check->fetch()){
    echo json_encode(["success"=>false,"message"=>"Folder not found"]);
    exit;
  }

  if(empty($_FILES["files"])){
    echo json_encode(["success"=>false,"message"=>"No files uploaded"]);
    exit;
  }

  $folderPath = $uploadRoot . "/" . $admin["id"] . "/" . $folderId;

  if(!is_dir($folderPath)){
    mkdir($folderPath, 0777, true);
  }

  foreach($_FILES["files"]["name"] as $i => $name){

    $tmp = $_FILES["files"]["tmp_name"][$i];
    $type = $_FILES["files"]["type"][$i];
    $size = $_FILES["files"]["size"][$i];

    $safeName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $name);
    $storedName = time() . "_" . bin2hex(random_bytes(5)) . "_" . $safeName;
    $path = $folderPath . "/" . $storedName;

    if(move_uploaded_file($tmp, $path)){

        $stmt = $pdo->prepare("
            INSERT INTO source_files
            (folder_id,admin_id,original_name,stored_name,file_path,file_type,file_size)
            VALUES (?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $folderId,
            $admin["id"],
            $name,
            $storedName,
            $path,
            $type,
            $size
        ]);

        $fileId = $pdo->lastInsertId();

        $userId = (int)($_POST["user_id"] ?? 0);

        if($userId > 0){

            $assign = $pdo->prepare("
                INSERT INTO user_source_files
                (file_id,user_id)
                VALUES (?,?)
            ");

            $assign->execute([
                $fileId,
                $userId
            ]);
        }

    } // end move_uploaded_file

} // end foreach

echo json_encode([
    "success" => true,
    "message" => "Files uploaded"
]);
exit;
}
if($method === "DELETE"){
  $fileId = (int)($_GET["id"] ?? 0);

  $stmt = $pdo->prepare("
    SELECT file_path FROM source_files
    WHERE id=? AND admin_id=?
  ");
  $stmt->execute([$fileId, $admin["id"]]);
  $file = $stmt->fetch();

  if(!$file){
    echo json_encode(["success"=>false,"message"=>"File not found"]);
    exit;
  }

  if(file_exists($file["file_path"])){
    unlink($file["file_path"]);
  }

  $del = $pdo->prepare("
    DELETE FROM source_files
    WHERE id=? AND admin_id=?
  ");
  $del->execute([$fileId, $admin["id"]]);

  echo json_encode(["success"=>true,"message"=>"File deleted"]);
  exit;
}