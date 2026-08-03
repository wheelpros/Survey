<?php

require_once "db.php";

header("Content-Type: application/json");


$input = json_decode(file_get_contents("php://input"), true);

$email = strtolower(trim($input["email"] ?? ""));


if(!$email){

    echo json_encode([
        "success"=>false,
        "message"=>"Email is required"
    ]);

    exit;
}



$token = bin2hex(random_bytes(32));

$expire = date(
    "Y-m-d H:i:s",
    strtotime("+30 minutes")
);



/*
 Check users first
*/

$stmt = $pdo->prepare("
SELECT id 
FROM users
WHERE LOWER(email)=?
LIMIT 1
");

$stmt->execute([$email]);

$user = $stmt->fetch();



if($user){


    $stmt=$pdo->prepare("
    UPDATE users
    SET reset_token=?,
    reset_expires=?
    WHERE id=?
    ");


    $stmt->execute([
        $token,
        $expire,
        $user["id"]
    ]);


}



/*
 Check admins
*/

$stmt = $pdo->prepare("
SELECT id 
FROM admins
WHERE LOWER(email)=?
LIMIT 1
");


$stmt->execute([$email]);

$admin=$stmt->fetch();



if($admin){


    $stmt=$pdo->prepare("
    UPDATE admins
    SET reset_token=?,
    reset_expires=?
    WHERE id=?
    ");


    $stmt->execute([
        $token,
        $expire,
        $admin["id"]
    ]);

}



echo json_encode([

"success"=>true,

"message"=>"If this email exists, a reset link has been sent."

]);

?>