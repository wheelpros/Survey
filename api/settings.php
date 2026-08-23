<?php
require_once "db.php";
header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ","",$headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token=? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if (!$admin || $admin['role'] !== 'owner') {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit;
}
    
if($_SERVER["REQUEST_METHOD"]=="GET"){

    $stmt=$pdo->prepare("
        SELECT sources_password
        FROM admins
        WHERE id=?
    ");

    $stmt->execute([$admin["id"]]);
    $row=$stmt->fetch();

    echo json_encode([
        "success"=>true,
        "role"=>$admin["role"], // مهم
        "hasPassword"=>!empty($row["sources_password"])
    ]);

    exit;
}

if($_SERVER["REQUEST_METHOD"]=="POST"){

    $input=json_decode(file_get_contents("php://input"),true);

    // Verify Password
    if(($input["action"] ?? "")=="verify"){

        $stmt=$pdo->prepare("
            SELECT sources_password
            FROM admins
            WHERE id=?
        ");

        $stmt->execute([$admin["id"]]);
        $row=$stmt->fetch();

        if(
            $row &&
            password_verify($input["password"] ?? "",$row["sources_password"])
        ){

            echo json_encode([
                "success"=>true
            ]);

        }else{

            echo json_encode([
                "success"=>false,
                "message"=>"Wrong password"
            ]);

        }

        exit;
    }

    // Activate / deactivate an admin. Blocks login only - nothing about
    // that admin (assignments, surveys, review history) is touched or
    // deleted, and reactivating restores full access exactly as it was.
    if(($input["action"] ?? "")=="toggle_admin_status"){

        $targetId = (int)($input["id"] ?? 0);
        $active = isset($input["active"]) && (int)$input["active"] === 1 ? 1 : 0;

        if(!$targetId){
            echo json_encode([
                "success"=>false,
                "message"=>"Admin id is required"
            ]);
            exit;
        }

        if($targetId === (int)$admin["id"]){
            echo json_encode([
                "success"=>false,
                "message"=>"You can't deactivate your own account"
            ]);
            exit;
        }

        $checkStmt = $pdo->prepare("SELECT id FROM admins WHERE id=? LIMIT 1");
        $checkStmt->execute([$targetId]);

        if(!$checkStmt->fetch()){
            echo json_encode([
                "success"=>false,
                "message"=>"Admin not found"
            ]);
            exit;
        }

        $updateStmt = $pdo->prepare("UPDATE admins SET active=? WHERE id=?");
        $updateStmt->execute([$active, $targetId]);

        echo json_encode([
            "success"=>true,
            "message"=>$active
                ? "Admin activated - they can log in again."
                : "Admin deactivated - they can no longer log in."
        ]);

        exit;
    }

    $current=trim($input["current_password"] ?? "");
    $new=trim($input["new_password"] ?? "");
    $confirm=trim($input["confirm_password"] ?? "");

    $stmt=$pdo->prepare("
        SELECT sources_password
        FROM admins
        WHERE id=?
    ");

    $stmt->execute([$admin["id"]]);
    $row=$stmt->fetch();

    // أول مرة
    if(empty($row["sources_password"])){

        if(strlen($new)<4){
            echo json_encode([
                "success"=>false,
                "message"=>"Minimum 4 characters"
            ]);
            exit;
        }

        if($new!=$confirm){
            echo json_encode([
                "success"=>false,
                "message"=>"Passwords do not match"
            ]);
            exit;
        }

        $hash=password_hash($new,PASSWORD_DEFAULT);

        $stmt=$pdo->prepare("
            UPDATE admins
            SET sources_password=?
            WHERE id=?
        ");

        $stmt->execute([
            $hash,
            $admin["id"]
        ]);

        echo json_encode([
            "success"=>true,
            "message"=>"Password saved"
        ]);

        exit;
    }

    // بعد أول مرة
    if(!password_verify($current,$row["sources_password"])){

        echo json_encode([
            "success"=>false,
            "message"=>"Current password is incorrect"
        ]);

        exit;
    }

    if(strlen($new)<4){

        echo json_encode([
            "success"=>false,
            "message"=>"Minimum 4 characters"
        ]);

        exit;
    }

    if($new!=$confirm){

        echo json_encode([
            "success"=>false,
            "message"=>"Passwords do not match"
        ]);

        exit;
    }

    $hash=password_hash($new,PASSWORD_DEFAULT);

    $stmt=$pdo->prepare("
        UPDATE admins
        SET sources_password=?
        WHERE id=?
    ");

    $stmt->execute([
        $hash,
        $admin["id"]
    ]);

    echo json_encode([
        "success"=>true,
        "message"=>"Password updated"
    ]);

    exit;
}