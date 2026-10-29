<?php
require_once "db.php";
require_once "events.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("
SELECT id
FROM admins
WHERE session_token=?
LIMIT 1
");

$stmt->execute([$token]);

if(!$stmt->fetch()){
    exit(json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]));
}

if(empty($_FILES["logo"]["tmp_name"])){
    exit(json_encode([
        "success" => false,
        "message" => "Choose image"
    ]));
}

$tmp = $_FILES["logo"]["tmp_name"];
$ext = strtolower(pathinfo($_FILES["logo"]["name"], PATHINFO_EXTENSION));

$allowed = ["png", "jpg", "jpeg", "svg"];

if(!in_array($ext, $allowed)){
    exit(json_encode([
        "success" => false,
        "message" => "Invalid image. Only PNG, JPG, JPEG, and SVG allowed."
    ]));
}

// المسار الصحيح لاستبدال ملف logo.png
$targetPath = __DIR__ . "/../logo.png";

if (move_uploaded_file($tmp, $targetPath)) {
    logEvent($pdo, EVENT_SETTINGS_CHANGED, "settings", 0, "Logo", "Replaced the portal logo");

    echo json_encode([
        "success" => true,
        "message" => "Logo updated successfully"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to save file. Check folder permissions."
    ]);
}