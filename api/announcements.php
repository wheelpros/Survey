<?php

/*
|--------------------------------------------------------------------------
| Announcements
|--------------------------------------------------------------------------
|
| Backs admin-announcement-form.html, plus the detail panel both notification
| pages open when an announcement row is clicked.
|
| Everything else in the notifications table is written by api/notify.php as a
| side effect of some other event. This is the one message an admin composes:
| a title, a description, an optional date, an optional image, aimed at either
| the team (admins) or the clients (portal users).
|
| Three actions, two audiences:
|
|   recipients  owner only  - the people the picker offers
|   create      owner only  - write the announcement, then fan it out
|   get         recipients  - the detail panel's payload
|
| Auth is the dual-audience check from notifications.php: the token is
| resolved against `users` first and `admins` second, and nothing downstream
| trusts an id from the request. Writing is owner-only on top of that.
|
| The checks, the fan-out and the read rule are in announcement-writes.php,
| shared with api/v1 (send_announcement, get_announcement).
|
| The table is ensureAnnouncementsTable() in db.php, mirrored by
| sql/announcements.sql.
|
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";
require_once "announcement-writes.php";

function reply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);

    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Who is calling
|--------------------------------------------------------------------------
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? "";

if (!$authHeader && isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authHeader = $_SERVER["HTTP_AUTHORIZATION"];
}

$token = trim(str_replace("Bearer", "", $authHeader));

if (!$token) {
    reply(false, "No token provided", [], 401);
}

// DDL before anything else: the create path opens a transaction further down,
// and MySQL commits implicitly around DDL. Same rule api/notify.php states.
ensureAnnouncementsTable($pdo);

$user = clientFromSessionToken($pdo, $token);

$admin = null;

if (!$user) {
    $stmt = $pdo->prepare("SELECT id, name, role FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        reply(false, "Unauthorized token", [], 401);
    }
}

$kind    = $user ? "user" : "admin";
$me      = (int) ($user ? $user["id"] : $admin["id"]);
$isOwner = !$user && can($admin, "announcements.send", $pdo);

$action = $_GET["action"] ?? $_POST["action"] ?? "";

/** Sending is the owner's alone; everyone else may only read what they were sent. */
function requireOwner($isOwner)
{
    if (!$isOwner) {
        reply(false, "You do not have permission to send announcements.", [], 403);
    }
}

/*
|--------------------------------------------------------------------------
| Recipients
|--------------------------------------------------------------------------
|
| Both lists in one payload: the form fetches once and re-renders the picker
| from memory when the audience tab changes.
|
| get-admins.php is deliberately not reused here - it hides the owner and
| scopes to whoever manages whom, which is the wrong question for "who can be
| sent an announcement".
|
*/

if ($action === "recipients") {
    requireOwner($isOwner);

    // The sender is dropped from their own audience, the way every fan-out
    // helper in notify.php drops the actor.
    $stmt = $pdo->prepare("
        SELECT id, name, email, role
        FROM admins
        WHERE active = 1 AND id <> ?
        ORDER BY FIELD(role, 'super_admin', 'account_manager', 'seo_admin'), name ASC
    ");
    $stmt->execute([$me]);
    $team = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT id, name, email, company_name
        FROM users
        WHERE approved = 1 AND account_owner_id IS NULL
        ORDER BY name ASC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    reply(true, "", [
        "team" => array_map(function ($row) {
            return [
                "id"    => (int) $row["id"],
                "name"  => $row["name"],
                "email" => $row["email"],
                "role"  => $row["role"],
            ];
        }, $team),
        "clients" => array_map(function ($row) {
            return [
                "id"      => (int) $row["id"],
                "name"    => $row["name"],
                "email"   => $row["email"],
                "company" => $row["company_name"],
            ];
        }, $clients),
    ]);
}

/*
|--------------------------------------------------------------------------
| Reading one announcement
|--------------------------------------------------------------------------
|
| The recipient predicate is the authorisation, the same way mark_read works
| in notifications.php: an id nobody sent you matches no notifications row, so
| there is nothing to return. The owner who wrote it can always reopen it.
|
*/

if ($action === "get") {
    $id = (int) ($_GET["id"] ?? 0);

    if ($id <= 0) {
        reply(false, "Announcement not found.", [], 404);
    }

    $row = announcementFor($pdo, $kind, $me, $id);

    if (!$row) {
        reply(false, "Announcement not found.", [], 404);
    }

    reply(true, "", [
        "announcement" => [
            "id"              => (int) $row["id"],
            "audience"        => $row["audience"],
            "title"           => $row["title"],
            "body"            => $row["body"],
            "event_date"      => $row["event_date"],
            "image_path"      => $row["image_path"],
            "created_by_name" => $row["created_by_name"],
            "created_at"      => $row["created_at"],
            "created_ts"      => (int) $row["created_ts"],
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| Image upload
|--------------------------------------------------------------------------
|
| The same validator admin-content.php uses for post media, kept local the way
| admin-login-slides.php keeps its own copy: MIME read off the file itself
| rather than trusted from the name, a random filename so nothing can be
| guessed or overwritten, and a web-relative path as the return value.
|
*/

function uploadAnnouncementImage($file, $uploadDir)
{
    if (!isset($file) || !is_array($file)) {
        return null;
    }

    $error = $file["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            reply(
                false,
                "Image is too large for the server (upload_max_filesize is "
                    . ini_get("upload_max_filesize") . ").",
                [],
                400
            );
        }
        reply(false, "Image upload failed.", [], 400);
    }

    if (($file["size"] ?? 0) > 3 * 1024 * 1024) {
        reply(false, "Image must be 3 MB or smaller.", [], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file["tmp_name"]);

    $allowed = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif"
    ];

    if (!isset($allowed[$mime])) {
        reply(false, "Only JPG, PNG, WEBP and GIF images are allowed.", [], 400);
    }

    $filename    = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        reply(false, "Could not save image.", [], 500);
    }

    return "uploads/announcements/" . $filename;
}

function deleteAnnouncementImage($relativePath)
{
    if (!$relativePath) {
        return;
    }

    $fullPath = dirname(__DIR__) . "/" . ltrim($relativePath, "/");

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| Sending
|--------------------------------------------------------------------------
*/

if ($action === "create" || ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "")) {
    requireOwner($isOwner);

    /*
    | An upload past post_max_size arrives with $_POST and $_FILES both empty,
    | so every field below would read as missing and the error would blame the
    | title. Same guard as admin-content.php.
    */
    if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        reply(
            false,
            "Upload is too large for the server (post_max_size is "
                . ini_get("post_max_size") . "). Use a smaller image.",
            [],
            413
        );
    }

    $audience = trim($_POST["audience"] ?? "");
    $scope    = trim($_POST["scope"] ?? "all");
    $posted   = $_POST["recipient_ids"] ?? "";

    try {
        [$title, $description, $eventDate] = checkAnnouncement($audience, $scope,
            $_POST["title"] ?? "", $_POST["description"] ?? "", $_POST["event_date"] ?? "");
        $recipientIds = announcementRecipientIds($pdo, $me, $audience, $scope,
            is_array($posted) ? $posted : explode(",", (string) $posted));
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage(), [], $e->status);
    }

    /*
    | Upload
    */

    $uploadDir = dirname(__DIR__) . "/uploads/announcements/";

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            reply(false, "Could not create upload directory.", [], 500);
        }
    }

    $imagePath = uploadAnnouncementImage($_FILES["image"] ?? null, $uploadDir);

    try {
        $announcementId = createAnnouncement($pdo, $me, $audience, $recipientIds,
            $title, $description, $eventDate, $imagePath);
    } catch (PortalWriteError $e) {
        // Nothing references the file now, so it would sit there forever.
        deleteAnnouncementImage($imagePath);
        reply(false, $e->getMessage(), [], $e->status);
    }

    $count = count($recipientIds);

    reply(true, "Announcement sent to " . $count . ($count === 1 ? " person." : " people."), [
        "id"    => $announcementId,
        "sent"  => $count,
    ]);
}

reply(false, "Unknown action.", [], 400);
