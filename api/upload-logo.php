<?php

/*
| The Settings page's logo upload. The Director's alone, like the rest of that
| panel; it needs the browser session, as the page is the only caller. The
| assistant's route is set_logo, through api/v1.
*/

require_once "db.php";
require_once "auth.php";
require_once "events.php";
require_once "site-logo-store.php";

header("Content-Type: application/json");

function logoReply($status, $success, $message)
{
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

$p = currentPrincipal($pdo);

if (!$p || $p["kind"] !== "admin" || $p["via"] !== "session") {
    logoReply(401, false, "Unauthorized");
}

if (!can($p, "settings.manage", $pdo)) {
    logoReply(403, false, "Only the Director can change the logo.");
}

// PHP drops a file over its own limit before this runs, leaving only the error.
if (in_array($_FILES["logo"]["error"] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    logoReply(400, false, "That logo is over 3 MB. Pick a smaller one.");
}

$tmp = $_FILES["logo"]["tmp_name"] ?? "";

if ($tmp === "" || !is_uploaded_file($tmp)) {
    logoReply(400, false, "Choose an image.");
}

if ($_FILES["logo"]["size"] > SITE_LOGO_MAX_BYTES) {
    logoReply(400, false, "That logo is over 3 MB. Pick a smaller one.");
}

$bytes = (string) file_get_contents($tmp);
$mime = siteLogoMime($bytes);

// By what's in the file, not its name: a renamed file is what breaks the logo.
if (!$mime) {
    logoReply(400, false, "That file isn't a PNG, JPG or SVG image.");
}

try {
    saveSiteLogo($pdo, $bytes, $mime);
} catch (PDOException $e) {
    error_log("upload-logo: " . $e->getMessage());
    logoReply(500, false, "The logo couldn't be saved. Please try again.");
}

logEvent($pdo, EVENT_SETTINGS_CHANGED, "settings", 0, "Logo", "Replaced the portal logo");

logoReply(200, true, "Logo updated successfully");
