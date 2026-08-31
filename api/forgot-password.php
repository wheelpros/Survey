<?php

require_once "db.php";
require_once "../PHPMailer/src/Exception.php";
require_once "../PHPMailer/src/PHPMailer.php";
require_once "../PHPMailer/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
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

/*
 Neither table matched. Stop here: sending a reset link to an address with no
 account emails a token that resets nothing, and every unknown address typed
 into the form becomes mail from your domain. The reply is the same either way,
 so this still tells an outsider nothing about who has an account.
*/

if(!$user && !$admin){

    echo json_encode([
        "success"=>true,
        "message"=>"If this email exists, a reset link has been sent."
    ]);

    exit;

}

$resetLink = 
"https://survey.websitezone.co.uk/reset-password.html?token=".$token;

$mail = new PHPMailer(true);


try {

    $mail->isSMTP();

    $mail->Host = "smtp.hostinger.com";

    $mail->SMTPAuth = true;

    $mail->Username = "survey@wzonevr.com";

    $mail->Password = "Survey1@!t";


    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port = 587;



    $mail->setFrom(
        "survey@wzonevr.com",
        "Survey from WZone"
    );


    $mail->addAddress($email);



    $mail->isHTML(true);


    $mail->Subject = "Reset Password";


    $mail->Body = "

    <h2>Password Reset Request</h2>

    <p>
    Click the button below to reset your password:
    </p>

    <a href='$resetLink'>
    Reset Password
    </a>

    <p>
    This link will expire after 30 minutes.
    </p>

    ";


    $mail->send();


}

catch(Exception $e){

    // The mailer's own error text names the SMTP host and account, so it goes
    // to the log rather than to whoever typed the address.
    error_log("forgot-password mail failed: " . $mail->ErrorInfo);

    echo json_encode([
        "success"=>false,
        "message"=>"Could not send the reset email. Please try again later."
    ]);

    exit;

}

echo json_encode([

"success"=>true,

"message"=>"If this email exists, a reset link has been sent."

]);

?>