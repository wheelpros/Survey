<?php

require_once "db.php";
require_once "admin-settings-writes.php";
require_once "events.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper: JSON Response
|--------------------------------------------------------------------------
*/

function response($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Admin Token
|--------------------------------------------------------------------------
*/

$headers = function_exists("getallheaders")
    ? getallheaders()
    : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? "";

if (!$authorization && isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authorization = $_SERVER["HTTP_AUTHORIZATION"];
}

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    response(false, "Unauthorized.", [], 401);
}

$token = trim($matches[1]);

if (!$token) {
    response(false, "Unauthorized.", [], 401);
}

/*
|--------------------------------------------------------------------------
| Validate Admin Token
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            role,
            session_token
        FROM admins
        WHERE session_token = ?
        LIMIT 1
    ");

    $stmt->execute([$token]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        response(false, "Invalid or expired admin session.", [], 401);
    }

} catch (Throwable $e) {

    response(
        false,
        "Authentication database error.",
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Any admin may manage the login slideshow
|--------------------------------------------------------------------------
|
| No role check here on purpose - this sits directly under the Website Logo
| panel on settings.html, which every admin can already use.
|
| Resolving the token against the admins table above is the authorisation:
| the same thing source-files.php, settings.php and upload-logo.php rely on.
| Only genuinely role-limited features narrow further, the way
| client-team.php does.
|
*/

ensureLoginSlidesTable($pdo);

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDir = dirname(__DIR__) . "/uploads/login-slides/";

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        response(false, "Could not create upload directory.", [], 500);
    }
}

// deleteSlideImage(), and the slide writes below, are in
// admin-settings-writes.php - shared with api/v1.

/*
|--------------------------------------------------------------------------
| Helper: Upload Image
|--------------------------------------------------------------------------
|
| Same rules as admin-content.php, minus SVG: these images are served to an
| unauthenticated page, and an SVG is a script host.
|
*/

function uploadSlideImage($file, $uploadDir)
{
    if (!isset($file) || !is_array($file)) {
        return null;
    }

    $error = $file["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {

        // PHP rejected it before we ever saw it. Say so plainly rather than
        // "upload failed", which sends people hunting for the wrong problem.
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            response(
                false,
                "Image is too large for the server (upload_max_filesize is "
                    . ini_get("upload_max_filesize") . ").",
                [],
                400
            );
        }

        if ($error === UPLOAD_ERR_NO_FILE) {
            response(false, "Please choose an image.", [], 400);
        }

        response(false, "Image upload failed.", [], 400);
    }

    /*
    | Max 3 MB
    */
    if (($file["size"] ?? 0) > 3 * 1024 * 1024) {
        response(false, "Image must be 3 MB or smaller.", [], 400);
    }

    /*
    | Check MIME type
    */
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mime = $finfo->file($file["tmp_name"]);

    $allowed = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif"
    ];

    if (!isset($allowed[$mime])) {
        response(false, "Only JPG, PNG, WEBP and GIF images are allowed.", [], 400);
    }

    /*
    | Minimum dimensions
    |
    | The slideshow fills half a desktop screen, so a small image is only
    | ever going to be stretched. settings.html checks the same floor before
    | it uploads; this is the one that actually holds.
    */
    $size = getimagesize($file["tmp_name"]);

    if (!$size) {
        response(false, "That image could not be read.", [], 400);
    }

    if ($size[0] < LOGIN_SLIDE_MIN_WIDTH || $size[1] < LOGIN_SLIDE_MIN_HEIGHT) {
        response(
            false,
            "Image is " . $size[0] . " × " . $size[1] . " px. The minimum is "
                . LOGIN_SLIDE_MIN_WIDTH . " × " . LOGIN_SLIDE_MIN_HEIGHT . " px.",
            [],
            400
        );
    }

    /*
    | Generate safe random filename
    */
    $filename =
        bin2hex(random_bytes(16))
        . "."
        . $allowed[$mime];

    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        response(false, "Could not save image.", [], 500);
    }

    return "uploads/login-slides/" . $filename;
}

/*
|--------------------------------------------------------------------------
| GET - list every slide, in display order
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        $stmt = $pdo->query("
            SELECT id, image_path, title, subtitle, position, created_at
            FROM login_slides
            ORDER BY position ASC, id ASC
        ");

        $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($slides as &$slide) {
            $slide["id"] = (int) $slide["id"];
            $slide["position"] = (int) $slide["position"];
        }
        unset($slide);

        response(true, "", [
            "slides"     => $slides,
            "max_slides" => LOGIN_SLIDES_MAX
        ]);

    } catch (Throwable $e) {

        response(false, "Could not load slides.", [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
|
| multipart with a `slide` file            -> add
| multipart with `slide` + action=replace  -> swap one slide's image
| JSON {"action":"update"}                 -> edit the captions
| JSON {"action":"reorder"}                -> save a new order
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    | When the request body exceeds post_max_size, PHP throws away $_POST and
    | $_FILES entirely, so an upload reads as "no file chosen" - the wrong
    | thing to send anyone chasing.
    */
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0
        && stripos($_SERVER["CONTENT_TYPE"] ?? "", "multipart/form-data") === 0) {
        response(
            false,
            "Upload is too large for the server (post_max_size is "
                . ini_get("post_max_size") . "). Use a smaller image.",
            [],
            413
        );
    }

    $body = [];

    if (empty($_POST) && empty($_FILES)) {
        $raw = file_get_contents("php://input");
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $action = trim($body["action"] ?? $_POST["action"] ?? "");

    /*
    |----------------------------------------------------------------------
    | Update captions
    |----------------------------------------------------------------------
    */

    if ($action === "update") {

        $id = (int) ($body["id"] ?? $_POST["id"] ?? 0);

        if ($id < 1) {
            response(false, "Slide not found.", [], 404);
        }

        $title = loginSlideTitle($body["title"] ?? $_POST["title"] ?? "");
        $subtitle = loginSlideSubtitle($body["subtitle"] ?? $_POST["subtitle"] ?? "");

        try {

            updateLoginSlideText($pdo, $id, $title, $subtitle);

            response(true, "Slide text saved.");

        } catch (PortalWriteError $e) {

            response(false, $e->getMessage(), [], $e->status);

        } catch (Throwable $e) {

            response(false, "Could not save slide text.", [], 500);
        }
    }

    /*
    |----------------------------------------------------------------------
    | Reorder
    |----------------------------------------------------------------------
    |
    | The order sent has to be exactly the ids we hold. Anything else means
    | the tab is stale - another tab added or removed a slide - and silently
    | applying it would drop a slide to position 0 rather than say so.
    |
    */

    if ($action === "reorder") {

        try {

            reorderLoginSlides($pdo, checkLoginSlideOrder($pdo, $body["order"] ?? null));

            response(true, "Slide order saved.");

        } catch (PortalWriteError $e) {

            // A stale order is the page's to fix by reloading: 400, as before.
            response(false, $e->getMessage(), [], $e->status === 422 ? 400 : $e->status);

        } catch (Throwable $e) {

            response(false, "Could not save the slide order.", [], 500);
        }
    }

    /*
    |----------------------------------------------------------------------
    | Replace one slide's image
    |----------------------------------------------------------------------
    |
    | The "change" button over a thumbnail in Settings. Captions, position
    | and the slide count are all untouched - only the file swaps, so this
    | never has to reason about the LOGIN_SLIDES_MAX cap.
    |
    */

    if ($action === "replace") {

        $id = (int) ($_POST["id"] ?? 0);

        if ($id < 1) {
            response(false, "Slide not found.", [], 404);
        }

        if (!isset($_FILES["slide"])) {
            response(false, "Please choose an image.", [], 400);
        }

        try {

            $stmt = $pdo->prepare("
                SELECT id, image_path
                FROM login_slides
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $slide = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            response(false, "Could not load the slide.", [], 500);
        }

        if (!$slide) {
            response(false, "Slide not found.", [], 404);
        }

        // Only written once the new file is safely on disk, so a rejected
        // upload leaves the existing image in place.
        $imagePath = uploadSlideImage($_FILES["slide"], $uploadDir);

        if (!$imagePath) {
            response(false, "Please choose an image.", [], 400);
        }

        try {

            $update = $pdo->prepare("
                UPDATE login_slides
                SET image_path = ?
                WHERE id = ?
            ");

            $update->execute([$imagePath, $id]);

            if ($update->rowCount() === 0) {

                // The row went away between the read above and this write.
                // Filenames are random, so "no change" is not a possibility.
                deleteSlideImage($imagePath);
                response(false, "Slide not found.", [], 404);
            }

            logEvent($pdo, EVENT_SETTINGS_CHANGED, "login_slide", $id, "", "Replaced the image of a login page slide");

        } catch (Throwable $e) {

            deleteSlideImage($imagePath);
            response(false, "Could not replace the image.", [], 500);
        }

        // Last, so a failure above never orphans the row from its image.
        deleteSlideImage($slide["image_path"]);

        response(true, "Slide image replaced.", [
            "id"         => $id,
            "image_path" => $imagePath
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Add a slide
    |----------------------------------------------------------------------
    */

    if (!isset($_FILES["slide"])) {
        response(false, "Please choose an image.", [], 400);
    }

    $title = mb_substr(trim((string) ($_POST["title"] ?? "")), 0, 120);
    $subtitle = mb_substr(trim((string) ($_POST["subtitle"] ?? "")), 0, 255);

    /*
    | Cheap pre-check so the common "already full" case never writes a file.
    | The authoritative count is the locked one inside the transaction below.
    */
    try {

        $count = (int) $pdo->query("SELECT COUNT(*) FROM login_slides")->fetchColumn();

        if ($count >= LOGIN_SLIDES_MAX) {
            response(
                false,
                "You already have " . LOGIN_SLIDES_MAX . " slides. Remove one first.",
                [],
                409
            );
        }

    } catch (Throwable $e) {

        response(false, "Could not load slides.", [], 500);
    }

    $imagePath = uploadSlideImage($_FILES["slide"], $uploadDir);

    if (!$imagePath) {
        response(false, "Please choose an image.", [], 400);
    }

    // The count is checked again under a lock - someone else may have taken
    // the last slot since the check above - and a refused add removes the
    // uploaded file rather than leave an orphan.
    try {

        response(true, "Slide added.", addLoginSlide($pdo, $imagePath, $title, $subtitle, $admin["id"]));

    } catch (PortalWriteError $e) {

        response(false, $e->getMessage(), [], $e->status);
    }
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
|
| DELETE api/admin-login-slides.php?id=4   (or a JSON body {"id":4})
|
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($_GET["id"] ?? 0);

    if (!$id) {
        $decoded = json_decode(file_get_contents("php://input"), true);

        if (is_array($decoded)) {
            $id = (int) ($decoded["id"] ?? 0);
        }
    }

    if ($id < 1) {
        response(false, "Slide not found.", [], 404);
    }

    // Closes the gap the delete leaves, so positions stay 0..n-1.
    try {

        removeLoginSlide($pdo, $id);

        response(true, "Slide removed.");

    } catch (PortalWriteError $e) {

        response(false, $e->getMessage(), [], $e->status);
    }
}

response(false, "Method not allowed.", [], 405);
