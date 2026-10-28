<?php

require_once "db.php";
require_once "notify.php";
require_once "content-writes.php";
require_once "upload-helpers.php";

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
| `super_admin` and `seo_admin` (see api/client-team.php), so every
| non-super admin was rejected with "You do not have permission."
|
| Resolving the token against the admins table above is the authorisation:
| the same thing source-files.php, settings.php and upload-logo.php rely on.
| Only genuinely role-limited features narrow further, the way
| client-team.php does.
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
ensureAttachmentsTable($pdo);

/*
| Who may do what with a post - canEditContent(), canPublishContent() and the
| rest - is in content-writes.php, shared with api/v1.
*/

/*
|--------------------------------------------------------------------------
| Helper: the teammates sent with a save
|--------------------------------------------------------------------------
|
| Sent either as repeated member_ids[] fields or as one comma-separated
| string, the way api/projects.php reads its team. Only the client's team
| survives (allowedContentAssignees() in content-writes.php), plus anyone
| already on the post, and never the person saving - they are on the post
| already as its author.
|
*/

function requestedAssigneeIds(PDO $pdo, $actorId, $company, array $current)
{
    $ids = $_POST["member_ids"] ?? [];

    if (!is_array($ids)) {
        $ids = array_filter(explode(",", (string) $ids), "strlen");
    }

    return allowedContentAssignees($pdo, $ids, $company, $current, $actorId);
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
| Helper: the uploaded images as a plain list
|--------------------------------------------------------------------------
|
| The form sends media[] - PHP files that as one array per field (name[],
| tmp_name[], ...) rather than one entry per file. A single `media` field,
| as older clients send, comes through as one file.
|
*/

function contentUploads()
{
    $field = $_FILES["media"] ?? null;

    if (!is_array($field) || !isset($field["error"])) {
        return [];
    }

    if (!is_array($field["error"])) {
        return $field["error"] === UPLOAD_ERR_NO_FILE ? [] : [$field];
    }

    $files = [];

    foreach (array_keys($field["error"]) as $i) {
        if ($field["error"][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            "name"     => $field["name"][$i] ?? "",
            "type"     => $field["type"][$i] ?? "",
            "tmp_name" => $field["tmp_name"][$i] ?? "",
            "error"    => $field["error"][$i],
            "size"     => $field["size"][$i] ?? 0,
        ];
    }

    return $files;
}

/*
|--------------------------------------------------------------------------
| Helper: Upload Images
|--------------------------------------------------------------------------
|
| Every file is checked before any is saved, so a bad third image does not
| leave the first two behind on disk with no post pointing at them.
|
*/

function uploadContentImages(array $files, $uploadDir)
{
    $extensions = array_map("checkContentImage", $files);

    $saved = [];

    foreach ($files as $i => $file) {
        $filename = bin2hex(random_bytes(16)) . "." . $extensions[$i];

        if (!move_uploaded_file($file["tmp_name"], $uploadDir . $filename)) {
            array_map("deleteContentImage", $saved);
            response(false, "Could not save image.", [], 500);
        }

        $saved[] = "uploads/content/" . $filename;
    }

    return $saved;
}

/*
|--------------------------------------------------------------------------
| Helper: Check one image, returning the extension to save it under
|--------------------------------------------------------------------------
*/

function checkContentImage($file)
{
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
    if (($file["size"] ?? 0) > CONTENT_IMAGE_MAX_BYTES) {
        response(false, "Image must be 3 MB or smaller.", [], 400);
    }

    /*
    | Check MIME type
    */
    $extension = contentImageExtension($file["tmp_name"]);

    if ($extension === null) {
        response(false, "Only JPG, PNG, WEBP and GIF images are allowed.", [], 400);
    }

    return $extension;
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
        | The teammates a post can be assigned to, for the form's picker:
        | the team of the client picked (?client=<company>), or anyone in
        | CONTENT_ASSIGNEE_ROLES while no client is picked.
        */
        if (!empty($_GET["candidates"])) {

            response(true, "Teammates loaded.", [
                "admins" => contentAssigneeCandidates($pdo, (string) ($_GET["client"] ?? ""))
            ]);
        }

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
            | author, the owner or an account manager gets Delete.
            */
            $content["created_by_id"] =
                (int) $content["created_by"];

            /*
            | Same visibility rule as the list. Without this the filter there
            | is decoration: the id is in the URL, so anyone could read a post
            | that never appeared in their grid. "Not found" rather than a 403,
            | so the response does not confirm the post exists.
            */
            $assigned = isContentAssignee($pdo, $id, $admin["id"]);

            if (!canViewAllContent($admin)
                && !$assigned
                && $content["created_by_id"] !== (int) $admin["id"]) {
                response(false, "Content not found.", [], 404);
            }

            /*
            | Match frontend field
            */
            $content["created_by"] =
                $content["created_by_name"];

            unset($content["created_by_name"]);

            $content["images"] = contentImages($content);

            $content["assignees"] = contentAssignees($pdo, [$id])[$id] ?? [];

            // Staff-only files; the client endpoint never selects them.
            $content["attachments"] = attachmentsFor($pdo, "content", [$id])[$id] ?? [];

            response(
                true,
                "Content loaded.",
                [
                    "content"     => $content,
                    "statuses"    => TASK_ASSIGNMENT_STATUSES,
                    "max_files"   => ATTACHMENT_MAX_FILES,
                    "max_file_bytes" => ATTACHMENT_MAX_BYTES,
                    "admin_id"    => (int) $admin["id"],
                    "admin_role"  => $admin["role"],
                    "can_edit"    => canEditContent($admin, $content["created_by_id"], $assigned),
                    "can_delete"  => canDeleteContent($admin, $content["created_by_id"]),
                    "can_assign"  => canAssignContent($admin, $content["created_by_id"]),
                    "can_publish" => canPublishContent($admin)
                ]
            );
        }

        /*
        | The library, narrowed to what this admin may see. Owner and account
        | manager get everything; everyone else gets the posts they created
        | or are assigned to, so the filter goes in the query rather than
        | being applied after the fetch - content nobody may read should
        | never leave the database.
        */
        $seesAll = canViewAllContent($admin);

        [$visibleSql, $visibleParams] = contentVisibleSql("c", (int) $admin["id"]);

        $scope = $seesAll ? "" : "WHERE $visibleSql";

        $stmt = $pdo->prepare("
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
                c.media_paths,
                c.post_date,
                c.post_time,
                c.publish_now,

                /* A scheduled post whose time has passed is live to clients,
                   so it reads as published here too. */
                " . contentStatusSql("c") . " AS status,
                c.created_at,
                c.updated_at,
                " . contentLiveAtSql("c") . " AS live_at,

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

            $scope

            ORDER BY c.created_at DESC
        ");

        // The first ? is the status CASE in the SELECT; the scope's come after.
        $stmt->execute($seesAll ? [contentNow()] : array_merge([contentNow()], $visibleParams));

        $contents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assignees = contentAssignees($pdo, array_column($contents, "id"));
        $files     = attachmentsFor($pdo, "content", array_column($contents, "id"));

        /* Each row says what this admin may do with it, so the grid shows
           Edit and Delete from the same rules the writes below enforce. */
        foreach ($contents as &$row) {
            $row["images"] = contentImages($row);
            unset($row["media_paths"]);

            $row["assignees"] = $assignees[(int) $row["id"]] ?? [];
            $row["attachments"] = $files[(int) $row["id"]] ?? [];

            $assigned = in_array(
                (int) $admin["id"],
                array_column($row["assignees"], "id"),
                true
            );

            $row["can_edit"] = canEditContent($admin, $row["created_by_id"], $assigned);
            $row["can_delete"] = canDeleteContent($admin, $row["created_by_id"]);
        }
        unset($row);

        response(
            true,
            "Content loaded.",
            [
                "content" => $contents,

                /* Who is asking. The owner, account managers and super admins
                   get the Create button. */
                "admin_id"   => (int) $admin["id"],
                "admin_role" => $admin["role"],
                "can_create" => canCreateContent($admin),
                "statuses"   => TASK_ASSIGNMENT_STATUSES
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
                . ini_get("post_max_size") . "). Use fewer or smaller images.",
            [],
            413
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Your own status: action=set_my_status (and the older set_done)
    |--------------------------------------------------------------------------
    |
    | Each teammate on a post moves their own row between To do, In progress
    | and Done, straight from the Assigned table - no Save needed. Only the
    | caller's own content_assignees row changes, whatever their role, so
    | nobody moves someone else's work, and someone not on the post has no row.
    | set_done (done=1/0) is kept for older callers and maps to done / todo.
    |
    */
    $action = (string) ($_POST["action"] ?? "");

    if ($action === "set_done" || $action === "set_my_status") {

        $contentId = (int) ($_POST["content_id"] ?? 0);

        if ($contentId <= 0) {
            response(false, "Invalid content ID.", [], 400);
        }

        $status = $action === "set_done"
            ? ((($_POST["done"] ?? "") === "1") ? "done" : "todo")
            : (string) ($_POST["status"] ?? "");

        try {
            $stmt = $pdo->prepare("SELECT id FROM content WHERE id = ? LIMIT 1");
            $stmt->execute([$contentId]);

            if (!$stmt->fetch()) {
                response(false, "Content not found.", [], 404);
            }

            setOwnContentStatus($pdo, $contentId, $admin["id"], $status);

        } catch (PortalWriteError $e) {

            response(false, $e->getMessage(), [], $e->status === 422 ? 400 : $e->status);

        } catch (Throwable $e) {

            response(false, "Could not save your status.", [], 500);
        }

        response(
            true,
            "Status set to " . TASK_ASSIGNMENT_STATUSES[$status] . ".",
            [
                "status"    => $status,
                "assignees" => contentAssignees($pdo, [$contentId])[$contentId] ?? []
            ]
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

    /*
    | Priority and notes are set on the content form only. priority_sent marks
    | that the form sent them, so a save from anywhere else (api/v1, an older
    | page) leaves the stored values alone - see the update below.
    */
    $setsPriority = ($_POST["priority_sent"] ?? "") === "1";

    $priority = strtolower(trim($_POST["priority"] ?? "medium"));
    if (!in_array($priority, ["low", "medium", "high"], true)) {
        $priority = "medium";
    }

    $notes = mb_substr(trim((string) ($_POST["notes"] ?? "")), 0, CONTENT_NOTES_MAX);

    /*
    |--------------------------------------------------------------------------
    | Who may write this
    |--------------------------------------------------------------------------
    |
    | Creating is for the owner, the account managers and the super admins.
    | Editing is open to whoever may see the post (canEditContent). The UI
    | hides what a role cannot do; this is what stops a hand-made request
    | getting through.
    |
    */

    $existing = null;

    if ($id <= 0) {

        if (!canCreateContent($admin)) {
            response(
                false,
                "Only the Director, an account manager or a super admin can create content.",
                [],
                403
            );
        }

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT *
                FROM content
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            response(false, "Could not find content.", [], 500);
        }

        if (!$existing) {
            response(false, "Content not found.", [], 404);
        }

        if (!$setsPriority) {
            $priority = (string) ($existing["priority"] ?? "medium");
            $notes = (string) ($existing["notes"] ?? "");
        }

        if (!canEditContent(
            $admin,
            $existing["created_by"],
            isContentAssignee($pdo, $id, $admin["id"])
        )) {
            response(
                false,
                "You can only edit content you created or are assigned to.",
                [],
                403
            );
        }
    }

    /*
    | The teammates. member_ids_sent marks that the form sent the list at all,
    | so ticking nobody - an empty list, which PHP drops - is not read as
    | "leave it alone". A save from someone who may not change the team (an
    | assignee filling the post in) leaves it exactly as it was.
    */
    $setsTeam =
        ($_POST["member_ids_sent"] ?? "") === "1"
        && canAssignContent($admin, $existing["created_by"] ?? $admin["id"]);

    $hadAssignees = $existing ? currentAssigneeIds($pdo, $id) : [];

    try {
        $assigneeIds = $setsTeam
            ? requestedAssigneeIds($pdo, $admin["id"], $client, $hadAssignees)
            : $hadAssignees;
    } catch (Throwable $e) {
        response(false, "Could not check the teammates.", [], 500);
    }

    /*
    | What each teammate does (the Assigned table), sent as JSON
    | {admin_id: text} by whoever may set the team. Status is never set here.
    */
    $workNotes = null;
    if (isset($_POST["assignee_notes"]) && canAssignContent($admin, $existing["created_by"] ?? $admin["id"])) {
        $workNotes = json_decode((string) $_POST["assignee_notes"], true);
        if (!is_array($workNotes)) {
            response(false, "The Assigned table could not be read.", [], 400);
        }
    }

    /*
    | Attachments: keep_attachments[] is what stays, attachments[] the new
    | files; keep_attachments_sent marks that the list was sent. Every new file
    | is checked now, before any image is saved.
    */
    $fileUploads = uploadedFiles("attachments");
    $keepFiles = ($_POST["keep_attachments_sent"] ?? "") === "1"
        ? (array) ($_POST["keep_attachments"] ?? [])
        : null;

    try {
        array_map("checkAttachment", $fileUploads);
    } catch (PortalWriteError $e) {
        response(false, $e->getMessage(), [], 400);
    }

    /*
    | Only the account manager and the owner decide when a post goes out.
    | Anyone else filling a post in leaves its schedule exactly as it was,
    | whatever the request asked for - otherwise adding a caption to a
    | scheduled post would quietly pull it back to draft. A post written by
    | such a role before this rule is still a draft and stays one.
    */
    $setsSchedule = canPublishContent($admin);

    if (!$setsSchedule) {
        $status = $existing["status"] ?? "draft";
        $publishNow = (int) ($existing["publish_now"] ?? 0);
        $postDate = (string) ($existing["post_date"] ?? "");
        $postTime = (string) ($existing["post_time"] ?? "");
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | A post starts life as a task, so the title is the only thing it must
    | have. Client, type, caption and images are filled in later.
    |
    */

    if (!$title) {
        response(false, "Campaign title is required.", [], 400);
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
    try {
        $link = (string) normalisePortalLink($link);
    } catch (PortalWriteError $e) {
        response(false, $e->getMessage(), [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduling Rules
    |--------------------------------------------------------------------------
    */

    if ($setsSchedule) {

        // resolveContentSchedule() in content-writes.php, shared with api/v1.
        // Otherwise the stored schedule, untouched - see $setsSchedule above.
        try {
            [$status, $postDate, $postTime] = resolveContentSchedule($status, $publishNow, $postDate, $postTime);
        } catch (PortalWriteError $e) {
            response(false, $e->getMessage(), [], 400);
        }

    }

    /*
    | A post with no client is shown to every client (api/user-content.php
    | keeps that for the rows saved before a client was compulsory). A draft
    | can wait for one; nothing goes out without one.
    */
    if ($status !== "draft" && $client === "") {
        response(false, "Pick a client before scheduling or publishing.", [], 400);
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
    | Images
    |--------------------------------------------------------------------------
    |
    | Up to CONTENT_MAX_IMAGES per post. The form sends the images it keeps as
    | keep_media[] (in order) and the new ones as media[]; the kept ones come
    | first. keep_media_sent marks that the list was sent at all, so keeping
    | none - an empty list, which PHP drops - is not read as "keep them all".
    |
    | A kept path is only honoured when the post already has it: the paths
    | end up in an href and an unlink, so nothing the request makes up gets in.
    |
    */

    $hadImages = $existing ? contentImages($existing) : [];

    if (($_POST["keep_media_sent"] ?? "") === "1") {
        $keep = array_map("strval", (array) ($_POST["keep_media"] ?? []));
        $keptImages = array_values(array_intersect(array_unique($keep), $hadImages));
    } else {
        $keptImages = $hadImages;
    }

    $uploads = contentUploads();

    if (count($keptImages) + count($uploads) > CONTENT_MAX_IMAGES) {
        response(
            false,
            "A post can have up to " . CONTENT_MAX_IMAGES . " images.",
            [],
            400
        );
    }

    $newImages = uploadContentImages($uploads, $uploadDir);

    $images = array_merge($keptImages, $newImages);

    $mediaPath = $images[0] ?? null;
    $mediaPaths = $images ? json_encode($images, JSON_UNESCAPED_SLASHES) : null;

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    if ($id <= 0) {

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
                    media_paths,
                    post_date,
                    post_time,
                    publish_now,
                    status,
                    priority,
                    notes,
                    created_by
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
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
                $mediaPaths,
                $postDate ?: null,
                $postTime ?: null,
                $publishNow,
                $status,
                $priority,
                $notes !== "" ? $notes : null,
                $admin["id"]
            ]);

            $newId = $pdo->lastInsertId();

            saveContentAssignees($pdo, $newId, [], $assigneeIds, $title, $admin["id"]);

            if ($workNotes !== null) {
                saveContentWorkNotes($pdo, $newId, $workNotes);
            }
            saveAttachments($pdo, "content", $newId, null, $fileUploads, $admin["id"]);

            // Only a post that is live has anything to announce. A draft or a
            // scheduled post notifies nobody until it actually publishes.
            if ($status === "published") {
                notifyUsersAtCompany(
                    $pdo,
                    $client,
                    NOTIFY_CONTENT_PUBLISHED,
                    "New content published",
                    $title,
                    "content.html?id=" . (int) $newId,
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
            | Delete the uploaded files if the DB insert failed
            */
            array_map("deleteContentImage", $newImages);

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
    |
    | The row was loaded and the edit allowed at the top of this block.
    |
    */

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
                media_paths = ?,
                post_date = ?,
                post_time = ?,
                publish_now = ?,
                status = ?,
                priority = ?,
                notes = ?,
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
            $mediaPaths,
            $postDate ?: null,
            $postTime ?: null,
            $publishNow,
            $status,
            $priority,
            $notes !== "" ? $notes : null,
            $id
        ]);

        saveContentAssignees($pdo, $id, $hadAssignees, $assigneeIds, $title, $admin["id"]);

        if ($workNotes !== null) {
            saveContentWorkNotes($pdo, $id, $workNotes);
        }
        $removedFiles = saveAttachments($pdo, "content", $id, $keepFiles, $fileUploads, $admin["id"]);

        // Only on the transition into published. Without this test every later
        // edit of a live post would notify the whole client all over again.
        if ($status === "published" && ($existing["status"] ?? "") !== "published") {
            notifyUsersAtCompany(
                $pdo,
                $client,
                NOTIFY_CONTENT_PUBLISHED,
                "New content published",
                $title,
                "content.html?id=" . (int) $id,
                (int) $admin["id"]
            );
        }

        /*
        | The images the post let go of, removed only now the row no longer
        | points at them - a failed update leaves the post as it was.
        */
        array_map("deleteContentImage", array_diff($hadImages, $images));
        deleteUploadedFiles($removedFiles);

        response(
            true,
            "Content updated successfully.",
            [
                "id" => $id
            ]
        );

    } catch (Throwable $e) {

        array_map("deleteContentImage", $newImages);

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
            SELECT media_path, media_paths, created_by
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
        | The owner, an account manager or the post's author. An assignee can
        | fill the post in but not delete it.
        */
        if (!canDeleteContent($admin, $content["created_by"])) {
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

        $stmt = $pdo->prepare("
            DELETE FROM content_assignees
            WHERE content_id = ?
        ");

        $stmt->execute([$id]);

        $removedFiles = deleteAttachmentRows($pdo, "content", $id);

        /*
        | Delete its images and files
        */
        array_map("deleteContentImage", contentImages($content));
        deleteUploadedFiles($removedFiles);

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
