<?php

require_once "db.php";
require_once "notify.php";

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
| Any admin may manage content
|--------------------------------------------------------------------------
|
| No role check here on purpose. This used to allow ["admin","super_admin"],
| but no account has the literal role "admin" - the roles in this system are
| `super_admin` and `seo_admin` (see api/admin-user-assignments.php), so every
| non-super admin was rejected with "You do not have permission."
|
| Resolving the token against the admins table above is the authorisation:
| the same thing source-files.php, settings.php and upload-logo.php rely on.
| Only genuinely super-admin-only features narrow further, the way
| admin-user-assignments.php does.
|
*/

/*
|--------------------------------------------------------------------------
| Late-added columns
|--------------------------------------------------------------------------
|
| `link` arrived after the table shipped, so add it lazily
| the way the profile columns are handled - see api/db.php.
|
*/

ensureContentColumns($pdo);
ensureNotificationsTable($pdo);

/*
|--------------------------------------------------------------------------
| Helper: who may edit or delete a post
|--------------------------------------------------------------------------
|
| A post belongs to the admin who wrote it. The one exception is `owner`,
| which sits above the roles and manages every post on the system. Every
| other role - super_admin, seo_admin, account_manager - is read-only on
| someone else's content no matter how senior it sounds.
|
*/

function canManageContent($admin, $creatorId)
{
    if (($admin["role"] ?? "") === "owner") {
        return true;
    }

    return (int) $creatorId === (int) $admin["id"];
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
                    COALESCE(a.name, 'Unknown') AS created_by_name,

                    /* The view page names the author by email, not by first
                       name - two admins can share a name, an address cannot. */
                    a.email AS created_by_email
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
            | Keep the raw creator id before the name overwrites it. The pages
            | use it to decide whether this admin owns the post - only the
            | author gets Edit/Delete, everyone else gets View.
            */
            $content["created_by_id"] =
                (int) $content["created_by"];

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
                    "content"    => $content,
                    "admin_id"   => (int) $admin["id"],
                    "admin_role" => $admin["role"],
                    "is_owner"   => canManageContent($admin, $content["created_by_id"])
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
                c.link,
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

                /* The raw id stays alongside the name: the grid compares it
                   with the logged-in admin to pick whether Edit/Delete show.
                   The email is what the detail panel prints - two admins can
                   share a name, an address cannot. */
                c.created_by AS created_by_id,

                a.email AS created_by_email,

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
                "content" => $contents,

                /* Who is asking. The page can then show Edit/Delete only on
                   the rows this admin created - or on everything, when the
                   role is `owner`. */
                "admin_id"   => (int) $admin["id"],
                "admin_role" => $admin["role"]
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

    /*
    | When the request body exceeds post_max_size, PHP throws away $_POST and
    | $_FILES entirely. Without this, every field reads as empty and the caller
    | is told "Campaign title is required", which is the wrong thing to chase.
    */
    if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        response(
            false,
            "Upload is too large for the server (post_max_size is "
                . ini_get("post_max_size") . "). Use a smaller image.",
            [],
            413
        );
    }

    $id = isset($_POST["id"])
        ? (int) $_POST["id"]
        : 0;

    $title = trim($_POST["title"] ?? "");
    $client = trim($_POST["client"] ?? "");
    $link = trim($_POST["link"] ?? "");

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

    /*
    | Every post belongs to one client. There used to be an "All clients"
    | option, which is why older rows can still have an empty client - they
    | keep working, but nothing new is saved that way.
    */
    if (!$client) {
        response(false, "A client is required.", [], 400);
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
    | The link is printed as an href on the content pages, so only http(s) is
    | accepted - javascript: and data: URLs never reach the browser.
    */
    if ($link !== "") {

        if (mb_strlen($link) > 500) {
            response(false, "Link is too long.", [], 400);
        }

        if (!preg_match('#^https?://#i', $link)) {
            $link = "https://" . ltrim($link, "/");
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            response(false, "Enter a valid link, e.g. https://example.com", [], 400);
        }
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
    | Who may publish
    |--------------------------------------------------------------------------
    |
    | Going live is the account manager's and the owner's call. Every other
    | admin - seo_admin and super_admin alike - writes drafts: the post is
    | saved in full, and whoever reviews it decides when it goes out.
    |
    | The form hides the scheduling panel for them, so this is what stops a
    | hand-made request publishing anyway. On an update the row's own status
    | is restored further down, so an author's later fix can neither publish a
    | draft nor unpublish a post somebody else already sent out.
    |
    */
    $canPublish = in_array(
        $admin["role"] ?? "",
        ["account_manager", "owner"],
        true
    );

    if (!$canPublish) {
        $status = "draft";
        $publishNow = 0;
        $postDate = null;
        $postTime = null;
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
    | Frontend limit is 10000 chars
    */
    if (mb_strlen($captionText) > 10000) {
        response(
            false,
            "Caption cannot exceed 10000 characters.",
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
                    link,
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
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $title,
                $client ?: null,
                $link ?: null,
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

            // Only a post that is live has anything to announce. A draft or a
            // scheduled post notifies nobody until it actually publishes.
            if ($status === "published") {
                notifyUsersAtCompany(
                    $pdo,
                    $client,
                    NOTIFY_CONTENT_PUBLISHED,
                    "New content published",
                    $title,
                    "content.html",
                    (int) $admin["id"]
                );
            }

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

        /*
        |----------------------------------------------------------------------
        | Ownership
        |----------------------------------------------------------------------
        |
        | The author, or the owner. super_admin, seo_admin and account_manager
        | are all read-only on someone else's post. The UI hides Edit for them;
        | this is what stops a hand-made request getting through anyway.
        |
        */
        if (!canManageContent($admin, $existing["created_by"])) {
            response(
                false,
                "You can only edit content you created.",
                [],
                403
            );
        }

        /*
        | An admin who cannot publish cannot change when a post goes out
        | either, so the row keeps the schedule it already had. Forcing
        | "draft" here instead would quietly pull a live post down the first
        | time its author fixed a typo.
        */
        if (!$canPublish) {
            $status      = $existing["status"];
            $publishNow  = (int) $existing["publish_now"];
            $postDate    = $existing["post_date"];
            $postTime    = $existing["post_time"];
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
                link = ?,
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
            $link ?: null,
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

        // Only on the transition into published. Without this test every later
        // edit of a live post would notify the whole client all over again.
        if ($status === "published" && ($existing["status"] ?? "") !== "published") {
            notifyUsersAtCompany(
                $pdo,
                $client,
                NOTIFY_CONTENT_PUBLISHED,
                "New content published",
                $title,
                "content.html",
                (int) $admin["id"]
            );
        }

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
            SELECT media_path, created_by
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
        | Same rule as the update: the author, or the owner.
        */
        if (!canManageContent($admin, $content["created_by"])) {
            response(
                false,
                "You can only delete content you created.",
                [],
                403
            );
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