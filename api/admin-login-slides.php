<?php

require_once "db.php";

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
| Only genuinely super-admin-only features narrow further, the way
| admin-user-assignments.php does.
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

/*
|--------------------------------------------------------------------------
| Helper: Delete Existing Image
|--------------------------------------------------------------------------
*/

function deleteSlideImage($relativePath)
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
| multipart with a `slide` file -> add
| JSON {"action":"update"}       -> edit the captions
| JSON {"action":"reorder"}      -> save a new order
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

        $title = mb_substr(trim((string) ($body["title"] ?? $_POST["title"] ?? "")), 0, 120);
        $subtitle = mb_substr(trim((string) ($body["subtitle"] ?? $_POST["subtitle"] ?? "")), 0, 255);

        try {

            $stmt = $pdo->prepare("
                UPDATE login_slides
                SET title = ?, subtitle = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $title !== "" ? $title : null,
                $subtitle !== "" ? $subtitle : null,
                $id
            ]);

            if ($stmt->rowCount() === 0) {

                // rowCount() is 0 both for "gone" and for "saved the same
                // text again", so only the missing row is an error.
                $check = $pdo->prepare("SELECT id FROM login_slides WHERE id = ? LIMIT 1");
                $check->execute([$id]);

                if (!$check->fetch()) {
                    response(false, "Slide not found.", [], 404);
                }
            }

            response(true, "Slide text saved.");

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

        $order = $body["order"] ?? null;

        if (!is_array($order) || !$order || count($order) > LOGIN_SLIDES_MAX) {
            response(false, "Slide order is out of date. Reload the page.", [], 400);
        }

        $ids = [];

        foreach ($order as $value) {

            if (!is_int($value) && !ctype_digit((string) $value)) {
                response(false, "Slide order is out of date. Reload the page.", [], 400);
            }

            $id = (int) $value;

            if ($id < 1 || in_array($id, $ids, true)) {
                response(false, "Slide order is out of date. Reload the page.", [], 400);
            }

            $ids[] = $id;
        }

        try {

            $pdo->beginTransaction();

            $stored = $pdo
                ->query("SELECT id FROM login_slides FOR UPDATE")
                ->fetchAll(PDO::FETCH_COLUMN);

            $stored = array_map("intval", $stored);

            $sortedStored = $stored;
            $sortedSent = $ids;
            sort($sortedStored);
            sort($sortedSent);

            if ($sortedStored !== $sortedSent) {
                $pdo->rollBack();
                response(false, "Slide order is out of date. Reload the page.", [], 400);
            }

            $stmt = $pdo->prepare("UPDATE login_slides SET position = ? WHERE id = ?");

            foreach ($ids as $index => $id) {
                $stmt->execute([$index, $id]);
            }

            $pdo->commit();

            response(true, "Slide order saved.");

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            response(false, "Could not save the slide order.", [], 500);
        }
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

    try {

        $pdo->beginTransaction();

        $count = (int) $pdo
            ->query("SELECT COUNT(*) FROM login_slides FOR UPDATE")
            ->fetchColumn();

        if ($count >= LOGIN_SLIDES_MAX) {

            // Someone else took the last slot between the check above and
            // this lock. Undo our upload rather than leave an orphan file.
            $pdo->rollBack();
            deleteSlideImage($imagePath);

            response(
                false,
                "You already have " . LOGIN_SLIDES_MAX . " slides. Remove one first.",
                [],
                409
            );
        }

        $position = (int) $pdo
            ->query("SELECT COALESCE(MAX(position), -1) + 1 FROM login_slides")
            ->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO login_slides (image_path, title, subtitle, position, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $imagePath,
            $title !== "" ? $title : null,
            $subtitle !== "" ? $subtitle : null,
            $position,
            $admin["id"]
        ]);

        $newId = (int) $pdo->lastInsertId();

        $pdo->commit();

        response(true, "Slide added.", [
            "id"         => $newId,
            "image_path" => $imagePath,
            "position"   => $position
        ]);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        deleteSlideImage($imagePath);

        response(false, "Could not add the slide.", [], 500);
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

    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, image_path
            FROM login_slides
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$id]);

        $slide = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slide) {
            $pdo->rollBack();
            response(false, "Slide not found.", [], 404);
        }

        $pdo->prepare("DELETE FROM login_slides WHERE id = ?")->execute([$id]);

        /*
        | Close the gap the delete left, so positions stay 0..n-1 and the
        | admin list never has to reason about holes.
        */
        $remaining = $pdo
            ->query("SELECT id FROM login_slides ORDER BY position ASC, id ASC")
            ->fetchAll(PDO::FETCH_COLUMN);

        $update = $pdo->prepare("UPDATE login_slides SET position = ? WHERE id = ?");

        foreach ($remaining as $index => $remainingId) {
            $update->execute([$index, (int) $remainingId]);
        }

        $pdo->commit();

        deleteSlideImage($slide["image_path"]);

        response(true, "Slide removed.");

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        response(false, "Could not remove the slide.", [], 500);
    }
}

response(false, "Method not allowed.", [], 405);
