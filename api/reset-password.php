<?php

require_once "db.php";

header("Content-Type: application/json");


$input = json_decode(file_get_contents("php://input"), true);


$token = $input["token"] ?? "";
$password = $input["password"] ?? "";


if(!$token || !$password){

    echo json_encode([
        "success"=>false,
        "message"=>"Invalid request"
    ]);

    exit;
}



if(strlen($password) < 8){

    echo json_encode([
        "success"=>false,
        "message"=>"Password must be at least 8 characters"
    ]);

    exit;

}



$newPassword = password_hash(
    $password,
    PASSWORD_DEFAULT
);



/*
 Check users
*/

$stmt = $pdo->prepare("
SELECT id
FROM users
WHERE reset_token = ?
AND reset_expires > NOW()
LIMIT 1
");


$stmt->execute([$token]);


$user = $stmt->fetch();



if($user){


    $stmt=$pdo->prepare("
    UPDATE users
    SET password=?,
        reset_token=NULL,
        reset_expires=NULL
    WHERE id=?
    ");


    $stmt->execute([
        $newPassword,
        $user["id"]
    ]);



    echo json_encode([

        "success"=>true,
        "message"=>"Password reset successfully"
        "type"=>"user"

    ]);

    exit;

}



/*
 Check admins
*/

$stmt = $pdo->prepare("
SELECT id
FROM admins
WHERE reset_token = ?
AND reset_expires > NOW()
LIMIT 1
");


$stmt->execute([$token]);


$admin = $stmt->fetch();



if($admin){


    $stmt=$pdo->prepare("
    UPDATE admins
    SET password=?,
        reset_token=NULL,
        reset_expires=NULL
    WHERE id=?
    ");


    $stmt->execute([
        $newPassword,
        $admin["id"]
    ]);



    echo json_encode([

        "success"=>true,
        "message"=>"Password reset successfully"
        "type"=>"admin"

    ]);

    exit;

}




echo json_encode([

    "success"=>false,

    "message"=>"Invalid or expired reset link"

]);

?>