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

$user = clientFromSessionToken($pdo, $token);

if (!$user) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$name = trim($_POST["name"] ?? "");
/* Not taken from the request: the company name decides which posts and files
   this account can see, so changing it is for staff (admin-user-details.php). */
$companyName = $user["company_name"];
// The website and description are the company's: the owner's to edit, and a
// teammate's request just keeps what's there.
$website = $user["is_account_owner"] ? trim($_POST["website"] ?? "") : (string) $user["website"];
$description = $user["is_account_owner"] ? trim($_POST["description"] ?? "") : (string) $user["description"];
$phone = trim($_POST["phone"] ?? "");
$whatsappInput = trim($_POST["whatsapp"] ?? "");
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

/* WhatsApp is stored in one shape - plus, country code, number - because that
   is what wa.me needs and what makes the stored value dialable on its own.
   normaliseWhatsApp() throws away the spacing people type and refuses a number
   with no country code in front of it, which is the mistake worth catching: a
   local number here reaches nobody and there is no way to tell from the value
   itself that it is wrong. */
$whatsapp = normaliseWhatsApp($whatsappInput);

if ($whatsappInput !== "" && $whatsapp === "") {
    echo json_encode([
        "success" => false,
        "message" => "Start your WhatsApp number with its country code, like +44 7911 123456"
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
        website = ?, description = ?, phone = ?,
        whatsapp = ?
    WHERE id = ?
");

$stmt->execute([
    $name,
    $profileImage,
    $user["is_account_owner"] ? $website : null,
    $user["is_account_owner"] ? $description : null,
    $phone,
    $whatsapp === "" ? null : $whatsapp,
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
        "phone" => $phone,
        "whatsapp" => $whatsapp
    ]
]);