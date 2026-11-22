<?php

require_once "db.php";
require_once "scope.php";

$token = $_GET["token"] ?? "";
$fileId = (int)($_GET["id"] ?? 0);

if (!$token || !$fileId) {
    http_response_code(401);
    die("Unauthorized");
}

// An admin may fetch an upload from a response they may open
// (response-details.php, scope.php's "response"); an approved user only
// their own, so the response view can render the file they submitted.
$admin = adminFromSessionToken($pdo, $token);
$ownerId = null;

if (!$admin) {
    $user = clientFromSessionToken($pdo, $token);

    if (!$user) {
        http_response_code(401);
        die("Unauthorized");
    }

    $ownerId = (int)$user["account_id"];
}

if ($ownerId === null) {
    [$scopeWhere, $scopeParams] = scopeSql($pdo, $admin, "response", "sr");
    $stmt = $pdo->prepare("
        SELECT f.original_name, f.file_path, f.file_type
        FROM survey_uploaded_files f
        JOIN survey_responses sr ON sr.id = f.response_id
        WHERE f.id = ?" . ($scopeWhere !== "" ? " AND " . $scopeWhere : "") . "
        LIMIT 1
    ");
    $stmt->execute(array_merge([$fileId], $scopeParams));
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
    http_response_code(404);
    die("File not found");
}

/* mode=view renders in-page, for image previews only. The type was sent by
   whoever uploaded the file, so it is checked against the bytes: an HTML or
   SVG file shown inline would run its script on the portal's origin, where
   the admin's token lives (audit S7). Everything else downloads. */
$previewTypes = ["image/jpeg", "image/png", "image/webp", "image/gif"];
$realType = (new finfo(FILEINFO_MIME_TYPE))->file($file["file_path"]);
$inline = (($_GET["mode"] ?? "") === "view") && in_array($realType, $previewTypes, true);
$disposition = $inline ? "inline" : "attachment";
$name = str_replace(['"', "\r", "\n"], "", basename($file["original_name"]));

header("Content-Type: " . ($inline ? $realType : "application/octet-stream"));
header("Content-Disposition: " . $disposition . "; filename=\"" . $name . "\"");
header("Content-Length: " . filesize($file["file_path"]));
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");

readfile($file["file_path"]);
exit;