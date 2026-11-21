<?php

// A one-off maintenance script: run it from the command line only, never
// over the web, where anyone could start it.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/db.php";

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