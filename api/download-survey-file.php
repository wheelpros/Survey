<?php

require_once "db.php";

$token = $_GET["token"] ?? "";
$fileId = (int)($_GET["id"] ?? 0);

if (!$token || !$fileId) {
    die("Unauthorized");
}

$stmt = $pdo->prepare("
    SELECT id
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$admin = $stmt->fetch();

// Admins may fetch any upload; an approved user may fetch only their own,
// so the response view can render the file they submitted.
$ownerId = null;

if (!$admin) {
    $user = clientFromSessionToken($pdo, $token);

    if (!$user) {
        die("Unauthorized");
    }

    $ownerId = (int)$user["account_id"];
}

if ($ownerId === null) {
    $stmt = $pdo->prepare("
        SELECT original_name, file_path, file_type
        FROM survey_uploaded_files
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$fileId]);
} else {
    $stmt = $pdo->prepare("
        SELECT original_name, file_path, file_type
        FROM survey_uploaded_files
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$fileId, $ownerId]);
}

$file = $stmt->fetch();

if (!$file || !file_exists($file["file_path"])) {
    die("File not found");
}

// mode=view renders in-page (image previews); anything else downloads.
$disposition = (($_GET["mode"] ?? "") === "view") ? "inline" : "attachment";

header("Content-Type: " . ($file["file_type"] ?: "application/octet-stream"));
header("Content-Disposition: " . $disposition . "; filename=\"" . basename($file["original_name"]) . "\"");
header("Content-Length: " . filesize($file["file_path"]));

readfile($file["file_path"]);
exit;