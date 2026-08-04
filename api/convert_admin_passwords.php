<?php

require_once "db.php";

$stmt = $pdo->query("SELECT id, password FROM admins");
$admins = $stmt->fetchAll();

foreach ($admins as $admin) {

    // لو الباسورد ليس Hash بالفعل
    if (strpos($admin['password'], '$2y$') !== 0) {

        $hash = password_hash(
            $admin['password'],
            PASSWORD_DEFAULT
        );

        $update = $pdo->prepare("
            UPDATE admins
            SET password = ?
            WHERE id = ?
        ");

        $update->execute([
            $hash,
            $admin['id']
        ]);
    }
}

echo "Done";