<?php

require_once "db.php";

$token=$_GET["token"] ?? "";
$fileId=(int)($_GET["id"] ?? 0);

$user = clientFromSessionToken($pdo, $token);

if(!$user){
    exit("Unauthorized");
}

$stmt=$pdo->prepare("
SELECT
sf.original_name,
sf.file_path

FROM user_source_files usf

JOIN source_files sf
ON sf.id=usf.file_id

WHERE
usf.user_id=?
AND sf.id=?
LIMIT 1
");

$stmt->execute([
    $user["account_id"],
    $fileId
]);

$file=$stmt->fetch();

if(!$file){
    exit("File not found");
}

header("Content-Type: application/octet-stream");
header('Content-Disposition: attachment; filename="'.$file["original_name"].'"');

readfile($file["file_path"]);
exit;