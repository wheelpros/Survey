<?php

require_once "db.php";
require_once "auth.php";
require_once "admin-settings-writes.php";

header("Content-Type: application/json");

// Available Admins on settings.html is admins.manage's - the Director, unless
// handed it - and the page shows the panel only to whoever holds it.
$admin = requireAdmin($pdo, "admins.manage", "Unauthorized");

// Every Admin, Super Admin and Account Manager, plus who each Admin has been
// distributed to - the same list api/v1's get_settings returns.
$admins = listTeamAdmins($pdo);


echo json_encode([
    "success" => true,
    "admins" => $admins
]);
