<?php
require_once "db.php";
header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ","",$headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token=? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if (!$admin || !in_array($admin['role'], ['owner', 'super_admin', 'account_manager'], true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit;
}
    
if($_SERVER["REQUEST_METHOD"]=="GET"){

    // Sources & Files password management is owner-only - everything else
    // in this file (toggle/distribute) is shared with super_admin/account_manager.
    if ($admin['role'] !== 'owner') {
        echo json_encode([
            "status" => "error",
            "message" => "Unauthorized"
        ]);
        exit;
    }

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

        // Sources password is owner-only.
        if ($admin['role'] !== 'owner') {
            echo json_encode([
                "success"=>false,
                "message"=>"Unauthorized"
            ]);
            exit;
        }

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
    //
    // The owner can toggle anyone (except themself). A super_admin /
    // account_manager can only toggle a plain Admin (seo_admin) that the
    // owner has actually distributed to them - never another manager,
    // never an admin distributed to someone else.
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

        $checkStmt = $pdo->prepare("SELECT id, role, managed_by_admin_id FROM admins WHERE id=? LIMIT 1");
        $checkStmt->execute([$targetId]);
        $target = $checkStmt->fetch();

        if(!$target){
            echo json_encode([
                "success"=>false,
                "message"=>"Admin not found"
            ]);
            exit;
        }

        if($admin["role"] !== "owner"){

            $isMyAssignedAdmin = $target["role"] === "seo_admin"
                && (int)($target["managed_by_admin_id"] ?? 0) === (int)$admin["id"];

            if(!$isMyAssignedAdmin){
                echo json_encode([
                    "success"=>false,
                    "message"=>"You can only manage admins distributed to you"
                ]);
                exit;
            }
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

    // Owner hands a plain Admin (seo_admin) account off to a super_admin or
    // account_manager, who can then activate/deactivate just that admin.
    // Pass managerId = 0/null to unassign (owner keeps full control either way).
    if(($input["action"] ?? "")=="distribute_admin"){

        if($admin["role"] !== "owner"){
            echo json_encode([
                "success"=>false,
                "message"=>"Only the owner can distribute admins"
            ]);
            exit;
        }

        $adminId = (int)($input["adminId"] ?? 0);
        $managerId = (int)($input["managerId"] ?? 0);

        if(!$adminId){
            echo json_encode([
                "success"=>false,
                "message"=>"Admin id is required"
            ]);
            exit;
        }

        $targetStmt = $pdo->prepare("SELECT id, role FROM admins WHERE id=? LIMIT 1");
        $targetStmt->execute([$adminId]);
        $targetAdmin = $targetStmt->fetch();

        if(!$targetAdmin || $targetAdmin["role"] !== "seo_admin"){
            echo json_encode([
                "success"=>false,
                "message"=>"Only Admin accounts can be distributed"
            ]);
            exit;
        }

        if($managerId){

            $managerStmt = $pdo->prepare("SELECT id, role FROM admins WHERE id=? LIMIT 1");
            $managerStmt->execute([$managerId]);
            $manager = $managerStmt->fetch();

            if(!$manager || !in_array($manager["role"], ["super_admin", "account_manager"], true)){
                echo json_encode([
                    "success"=>false,
                    "message"=>"The selected manager must be a super admin or account manager"
                ]);
                exit;
            }

            $updateStmt = $pdo->prepare("UPDATE admins SET managed_by_admin_id=? WHERE id=?");
            $updateStmt->execute([$managerId, $adminId]);

            echo json_encode([
                "success"=>true,
                "message"=>"Admin distributed successfully"
            ]);

        }else{

            $updateStmt = $pdo->prepare("UPDATE admins SET managed_by_admin_id=NULL WHERE id=?");
            $updateStmt->execute([$adminId]);

            echo json_encode([
                "success"=>true,
                "message"=>"Admin unassigned"
            ]);
        }

        exit;
    }

    // Everything below (sources password change) is owner-only.
    if ($admin['role'] !== 'owner') {
        echo json_encode([
            "success"=>false,
            "message"=>"Unauthorized"
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