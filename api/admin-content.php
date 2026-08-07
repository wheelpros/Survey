<?php

require_once "../db.php";

header("Content-Type: application/json; charset=UTF-8");

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
|
| Same authentication idea used by your existing admin APIs:
| Authorization: Bearer admin_token
|
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
| Allow Admin / Super Admin
|--------------------------------------------------------------------------
*/

if (
    !in_array(
        strtolower($admin["role"] ?? ""),
        ["admin", "super_admin"],
        true
    )
) {
    response(false, "You do not have permission.", [], 403);
}

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDir = dirname(__DIR__) . "/uploads/content/";

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

function deleteContentImage($relativePath)
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
*/

function uploadContentImage($file, $uploadDir)
{
    if (!isset($file) || !is_array($file)) {
        return null;
    }

    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        response(false, "Image upload failed.", [], 400);
    }

    /*
    | Max 1 MB
    */
    if (($file["size"] ?? 0) > 1024 * 1024) {
        response(false, "Image must be 1 MB or smaller.", [], 400);
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

    return "uploads/content/" . $filename;
}

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| GET api/admin-content.php
| GET api/admin-content.php?id=5
|
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        /*
        | Single content
        */
        if (isset($_GET["id"]) && $_GET["id"] !== "") {

            $id = (int) $_GET["id"];

            if ($id <= 0) {
                response(false, "Invalid content ID.", [], 400);
            }

            $stmt = $pdo->prepare("
                SELECT
                    c.*,
                    COALESCE(a.name, 'Unknown') AS created_by_name
                FROM content c
                LEFT JOIN admins a
                    ON a.id = c.created_by
                WHERE c.id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $content = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$content) {
                response(false, "Content not found.", [], 404);
            }

            /*
            | Match frontend field
            */
            $content["created_by"] =
                $content["created_by_name"];

            unset($content["created_by_name"]);

            response(
                true,
                "Content loaded.",
                [
                    "content" => $content
                ]
            );
        }

        /*
        | All content
        */
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.title,
                c.client,
                c.caption,
                c.content_type,
                c.type_label,
                c.platform,
                c.category,
                c.orientation,
                c.media_path,
                c.post_date,
                c.post_time,
                c.publish_now,
                c.status,
                c.created_at,
                c.updated_at,

                COALESCE(a.name, 'Unknown') AS created_by

            FROM content c

            LEFT JOIN admins a
                ON a.id = c.created_by

            ORDER BY c.created_at DESC
        ");

        $contents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(
            true,
            "Content loaded.",
            [
                "content" => $contents
            ]
        );

    } catch (Throwable $e) {

        response(
            false,
            "Failed to load content.",
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
|
| Create:
| POST without id
|
| Update:
| POST with id
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = isset($_POST["id"])
        ? (int) $_POST["id"]
        : 0;

    $title = trim($_POST["title"] ?? "");
    $client = trim($_POST["client"] ?? "");

    $caption = $_POST["caption"] ?? "";

    $contentType = trim($_POST["content_type"] ?? "");
    $typeLabel = trim($_POST["type_label"] ?? "");
    $platform = trim($_POST["platform"] ?? "");
    $category = trim($_POST["category"] ?? "");

    $orientation = trim($_POST["orientation"] ?? "horizontal");

    $postDate = trim($_POST["post_date"] ?? "");
    $postTime = trim($_POST["post_time"] ?? "");

    $publishNow =
        isset($_POST["publish_now"])
        && $_POST["publish_now"] == "1"
        ? 1
        : 0;

    $status = trim($_POST["status"] ?? "draft");

    $removeMedia =
        isset($_POST["remove_media"])
        && $_POST["remove_media"] == "1";

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (!$title) {
        response(false, "Campaign title is required.", [], 400);
    }

    if (!$contentType) {
        response(false, "Content type is required.", [], 400);
    }

    if (!in_array(
        $orientation,
        ["horizontal", "vertical"],
        true
    )) {
        response(false, "Invalid orientation.", [], 400);
    }

    if (!in_array(
        $status,
        ["draft", "scheduled", "published"],
        true
    )) {
        response(false, "Invalid status.", [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduling Rules
    |--------------------------------------------------------------------------
    */

    if ($publishNow) {

        $postDate = null;
        $postTime = null;

        $status = "published";

    } elseif ($status === "scheduled") {

        if (!$postDate || !$postTime) {
            response(
                false,
                "Post date and time are required.",
                [],
                400
            );
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Caption validation
    |--------------------------------------------------------------------------
    */

    $captionText = trim(
        html_entity_decode(
            strip_tags($caption),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8"
        )
    );

    /*
    | Frontend limit is 2200 chars
    */
    if (mb_strlen($captionText) > 2200) {
        response(
            false,
            "Caption cannot exceed 2200 characters.",
            [],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    if ($id <= 0) {

        $mediaPath = null;

        /*
        | Upload new image
        */
        if (isset($_FILES["media"])) {
            $mediaPath = uploadContentImage(
                $_FILES["media"],
                $uploadDir
            );
        }

        try {

            $stmt = $pdo->prepare("
                INSERT INTO content (
                    title,
                    client,
                    caption,
                    content_type,
                    type_label,
                    platform,
                    category,
                    orientation,
                    media_path,
                    post_date,
                    post_time,
                    publish_now,
                    status,
                    created_by
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $title,
                $client ?: null,
                $caption ?: null,
                $contentType,
                $typeLabel ?: null,
                $platform ?: null,
                $category ?: null,
                $orientation,
                $mediaPath,
                $postDate ?: null,
                $postTime ?: null,
                $publishNow,
                $status,
                $admin["id"]
            ]);

            $newId = $pdo->lastInsertId();

            response(
                true,
                $status === "draft"
                    ? "Content saved as draft."
                    : (
                        $status === "published"
                            ? "Content published."
                            : "Content scheduled."
                    ),
                [
                    "id" => (int) $newId
                ]
            );

        } catch (Throwable $e) {

            /*
            | Delete uploaded file if DB insert failed
            */
            if ($mediaPath) {
                deleteContentImage($mediaPath);
            }

            response(
                false,
                "Could not create content.",
                [],
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM content
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            response(false, "Content not found.", [], 404);
        }

    } catch (Throwable $e) {

        response(
            false,
            "Could not find content.",
            [],
            500
        );
    }

    /*
    | Keep old image by default
    */
    $mediaPath = $existing["media_path"];

    /*
    | Remove image
    */
    if ($removeMedia) {

        if ($mediaPath) {
            deleteContentImage($mediaPath);
        }

        $mediaPath = null;
    }

    /*
    | Replace image
    */
    if (isset($_FILES["media"])) {

        $newMediaPath = uploadContentImage(
            $_FILES["media"],
            $uploadDir
        );

        if ($mediaPath) {
            deleteContentImage($mediaPath);
        }

        $mediaPath = $newMediaPath;
    }

    try {

        $stmt = $pdo->prepare("
            UPDATE content
            SET
                title = ?,
                client = ?,
                caption = ?,
                content_type = ?,
                type_label = ?,
                platform = ?,
                category = ?,
                orientation = ?,
                media_path = ?,
                post_date = ?,
                post_time = ?,
                publish_now = ?,
                status = ?,
                updated_at = CURRENT_TIMESTAMP

            WHERE id = ?
        ");

        $stmt->execute([
            $title,
            $client ?: null,
            $caption ?: null,
            $contentType,
            $typeLabel ?: null,
            $platform ?: null,
            $category ?: null,
            $orientation,
            $mediaPath,
            $postDate ?: null,
            $postTime ?: null,
            $publishNow,
            $status,
            $id
        ]);

        response(
            true,
            "Content updated successfully.",
            [
                "id" => $id
            ]
        );

    } catch (Throwable $e) {

        response(
            false,
            "Could not update content.",
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = isset($_GET["id"])
        ? (int) $_GET["id"]
        : 0;

    /*
    | Also support JSON body
    */
    if (!$id) {

        $input = json_decode(
            file_get_contents("php://input"),
            true
        );

        $id = (int) ($input["id"] ?? 0);
    }

    if ($id <= 0) {
        response(false, "Invalid content ID.", [], 400);
    }

    try {

        $stmt = $pdo->prepare("
            SELECT media_path
            FROM content
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        $content = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$content) {
            response(false, "Content not found.", [], 404);
        }

        /*
        | Delete database row
        */
        $stmt = $pdo->prepare("
            DELETE FROM content
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        /*
        | Delete image
        */
        if (!empty($content["media_path"])) {
            deleteContentImage(
                $content["media_path"]
            );
        }

        response(
            true,
            "Content deleted successfully."
        );

    } catch (Throwable $e) {

        response(
            false,
            "Could not delete content.",
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Unsupported Method
|--------------------------------------------------------------------------
*/

response(
    false,
    "Method not allowed.",
    [],
    405
);