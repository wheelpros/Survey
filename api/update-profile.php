<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "No token provided"
    ]);
    exit;
}

ensureUserProfileColumns($pdo);

$stmt = $pdo->prepare("
    SELECT id, name, email, approved, profile_image
    FROM users
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || (int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$name = trim($_POST["name"] ?? "");
$companyName = trim($_POST["company_name"] ?? "");
$website = trim($_POST["website"] ?? "");
$description = trim($_POST["description"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$profileImage = $user["profile_image"];

if (!$name) {
    echo json_encode([
        "success" => false,
        "message" => "Name is required"
    ]);
    exit;
}

// The field is typed as a bare domain more often than not, so accept that and
// store something a link can actually point at.
if ($website !== "" && !preg_match("#^https?://#i", $website)) {
    $website = "https://" . $website;
}

if ($website !== "" && !filter_var($website, FILTER_VALIDATE_URL)) {
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid website address"
    ]);
    exit;
}

if ($phone !== "" && !preg_match("/^[0-9+\-\s().]{6,25}$/", $phone)) {
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid phone number"
    ]);
    exit;
}

if (!empty($_FILES["profile_image"]["name"])) {

    $allowedTypes = ["image/jpeg", "image/png", "image/webp"];
    $fileType = $_FILES["profile_image"]["type"];

    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode([
            "success" => false,
            "message" => "Only JPG, PNG, WEBP images allowed"
        ]);
        exit;
    }

    $uploadDir = __DIR__ . "/../uploads/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $ext = pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION);
    $fileName = "profile_" . $user["id"] . "_" . time() . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["profile_image"]["tmp_name"], $targetPath)) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to upload image"
        ]);
        exit;
    }

    $profileImage = "uploads/" . $fileName;
}

$stmt = $pdo->prepare("
    UPDATE users
    SET name = ?, profile_image = ?,
        company_name = ?, website = ?, description = ?, phone = ?
    WHERE id = ?
");

$stmt->execute([
    $name,
    $profileImage,
    $companyName,
    $website,
    $description,
    $phone,
    $user["id"]
]);

echo json_encode([
    "success" => true,
    "message" => "Profile updated successfully",
    "user" => [
        "id" => $user["id"],
        "name" => $name,
        "email" => $user["email"],
        "profile_image" => $profileImage,
        "company_name" => $companyName,
        "website" => $website,
        "description" => $description,
        "phone" => $phone
    ]
]);