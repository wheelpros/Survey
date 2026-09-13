<?php

/*
|--------------------------------------------------------------------------
| Project tasks: the updates published against a project
|--------------------------------------------------------------------------
|
| Backs the task card grid on project-details.html and the whole of
| project-task-form.html. Projects themselves live in api/projects.php - a
| separate file because this one has its own upload directory, its own size
| cap and its own role gates, and one file covering both would be two POST
| branches told apart by a hidden field.
|
| Routed on REQUEST_METHOD, same reason as projects.php: the form posts an
| image, so the body is multipart and PUT would arrive with no $_FILES.
|
| There is no review step. A task is `draft` or `published` and nothing else.
|
*/

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

/* The stated contract on the dropzone. Kept as a constant so the page can print
   the cap from the API instead of hardcoding the number in two places that can
   drift apart. Matches PROJECT_IMAGE_MAX_BYTES in projects.php: one number for
   every image in Project Management, rather than a task cap a third the size of
   the project cap for no reason anyone using it could guess. */
const TASK_IMAGE_MAX_BYTES = 3 * 1024 * 1024;

/* The rich-text counter under the editor says 0 / 2000, and it counts text,
   not the markup the editor wraps around it. */
const TASK_DESCRIPTION_MAX_CHARS = 2000;

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
            role
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
    response(false, "Authentication database error.", [], 500);
}

ensureProjectTables($pdo);

/* Before any transaction, per rule 1 in notify.php - CREATE TABLE is DDL and
   MySQL commits implicitly around it. The POST branch below announces a task
   the first time it goes live. */
ensureNotificationsTable($pdo);

/*
|--------------------------------------------------------------------------
| Which projects this admin may see
|--------------------------------------------------------------------------
|
| The same predicate as projects.php, kept identical on purpose: a task is
| reachable exactly when its project is. Assumes the projects table is
| aliased `p`.
|
*/

function projectScope(array $admin)
{
    if (($admin["role"] ?? "") === "owner") {
        return ["", []];
    }

    $adminId = (int) $admin["id"];

    $sql = "(
            p.client_id IN (
                SELECT user_id FROM admin_user_assignments WHERE admin_id = ?
            )
         OR p.account_manager_admin_id = ?
         OR p.id IN (
                SELECT project_id FROM project_members WHERE admin_id = ?
            )
    )";

    return [$sql, [$adminId, $adminId, $adminId]];
}

/*
| Null when the project does not exist OR is out of scope. The caller answers
| 404 either way, so nothing confirms the existence of a project this admin
| cannot reach.
*/

function loadScopedProject(PDO $pdo, array $admin, $projectId)
{
    [$scopeSql, $scopeParams] = projectScope($admin);

    $sql = "
        SELECT
            p.id,
            p.title,
            p.client_id,
            p.account_manager_admin_id,
            p.created_by_admin_id,
            u.company_name,
            u.name AS client_name
        FROM projects p
        LEFT JOIN users u ON u.id = p.client_id
        WHERE p.id = ?
    ";

    $params = [(int) $projectId];

    if ($scopeSql !== "") {
        $sql .= " AND " . $scopeSql;
        $params = array_merge($params, $scopeParams);
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/*
|--------------------------------------------------------------------------
| Who may write a task
|--------------------------------------------------------------------------
|
| Wider than creating a project, because writing updates is the day job of the
| people put on one. An seo_admin gets in only through membership - being able
| to see a project through a client assignment does not make you part of the
| team working on it.
|
*/

function canWriteTask(PDO $pdo, array $admin, array $project)
{
    if (in_array($admin["role"] ?? "", ["owner", "account_manager", "super_admin"], true)) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1 FROM project_members WHERE project_id = ? AND admin_id = ? LIMIT 1
    ");
    $stmt->execute([(int) $project["id"], (int) $admin["id"]]);

    return (bool) $stmt->fetch();
}

/*
| Editing somebody else's task is a narrower thing than writing your own: the
| owner and the account managers, or the author. An seo_admin cannot rewrite a
| teammate's update.
*/

function canEditTask(array $admin, array $task)
{
    if (in_array($admin["role"] ?? "", ["owner", "account_manager"], true)) {
        return true;
    }

    return (int) $task["created_by_admin_id"] === (int) $admin["id"];
}

/* TASK_IS_LIVE_SQL - the one predicate that decides whether a task has gone
   live - lives in db.php beside ensureProjectTables(). This file and
   api/projects.php select it AS is_live so the grid can badge a scheduled
   task; api/user-projects.php puts the same expression in its WHERE. A second
   copy of it here would be a silent disclosure the day the two drifted. */

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDir = dirname(__DIR__) . "/uploads/project-tasks/";

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        response(false, "Could not create upload directory.", [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Helper: Delete Existing Image
|--------------------------------------------------------------------------
|
| deleteContentImage() from admin-content.php. Copied rather than shared
| because the upload helper calls this file's own response(), the same reason
| announcements.php keeps its own pair.
|
*/

function deleteTaskImage($relativePath)
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
| uploadContentImage() from admin-content.php with the announcements.php
| refinement - an empty file input returns null rather than an error - and a
| 1 MB cap instead of 3 MB, because there are a dozen of these per project.
|
| The type is sniffed from the file itself, never the name or the browser's
| claim, and the stored name is random so nothing is guessable or overwritable.
|
*/

function uploadTaskImage($file, $uploadDir)
{
    if (!isset($file) || !is_array($file)) {
        return null;
    }

    $error = $file["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

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

    if (($file["size"] ?? 0) > TASK_IMAGE_MAX_BYTES) {
        response(false, "Image must be 1 MB or smaller.", [], 400);
    }

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

    $filename =
        bin2hex(random_bytes(16))
        . "."
        . $allowed[$mime];

    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        response(false, "Could not save image.", [], 500);
    }

    return "uploads/project-tasks/" . $filename;
}

/* yyyy-mm-dd from a native date input; anything else is a tampered client. */

function validDate($value)
{
    $value = trim((string) $value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("Y-m-d", $value);

    if (!$date || $date->format("Y-m-d") !== $value) {
        return null;
    }

    return $value;
}

/* A native time input sends HH:MM; HH:MM:SS is accepted too, since the column
   is a TIME and a round-tripped value comes back with seconds on it. */

function validTime($value)
{
    $value = trim((string) $value);

    if ($value === "") {
        return null;
    }

    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
        return null;
    }

    return strlen($value) === 5 ? $value . ":00" : $value;
}

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| GET api/project-tasks.php?project_id=42   the grid, refreshed after an edit
| GET api/project-tasks.php?id=7            one task, to prefill the form
|
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        /*
        | One task. Returns its project too, so the form's breadcrumb comes
        | from the database rather than a title passed in the query string -
        | which is not something to trust or to render.
        */

        if (isset($_GET["id"])) {

            $stmt = $pdo->prepare("
                SELECT t.*, " . TASK_IS_LIVE_SQL . " AS is_live
                FROM project_tasks t
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->execute([(int) $_GET["id"]]);

            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                response(false, "Task not found.", [], 404);
            }

            $project = loadScopedProject($pdo, $admin, $task["project_id"]);

            if (!$project) {
                response(false, "Task not found.", [], 404);
            }

            response(true, "Task loaded.", [
                "task"       => $task,
                "project"    => $project,
                "admin_id"   => (int) $admin["id"],
                "admin_role" => $admin["role"],
                "can_edit"   => canEditTask($admin, $task),
                "max_bytes"  => TASK_IMAGE_MAX_BYTES,
                "max_chars"  => TASK_DESCRIPTION_MAX_CHARS,
            ]);
        }

        /*
        | Every task on a project.
        */

        $projectId = (int) ($_GET["project_id"] ?? 0);

        if ($projectId <= 0) {
            response(false, "Which project?", [], 400);
        }

        $project = loadScopedProject($pdo, $admin, $projectId);

        if (!$project) {
            response(false, "Project not found.", [], 404);
        }

        /* Same rule as projects.php: the owner, the account managers and this
           project's manager see every draft, everyone else sees the published
           tasks plus their own. */

        $seesEveryDraft =
            in_array($admin["role"], ["owner", "account_manager"], true)
            || (int) $project["account_manager_admin_id"] === (int) $admin["id"];

        $sql = "
            SELECT t.*, a.name AS created_by_name, " . TASK_IS_LIVE_SQL . " AS is_live
            FROM project_tasks t
            LEFT JOIN admins a ON a.id = t.created_by_admin_id
            WHERE t.project_id = ?
        ";

        $params = [$projectId];

        if (!$seesEveryDraft) {
            $sql .= " AND (t.status <> 'draft' OR t.created_by_admin_id = ?)";
            $params[] = (int) $admin["id"];
        }

        $sql .= " ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        response(true, "Tasks loaded.", [
            "tasks"       => $stmt->fetchAll(PDO::FETCH_ASSOC),
            "project"     => $project,
            "admin_id"    => (int) $admin["id"],
            "admin_role"  => $admin["role"],
            "can_write"   => canWriteTask($pdo, $admin, $project),
            "max_bytes"   => TASK_IMAGE_MAX_BYTES,
            "max_chars"   => TASK_DESCRIPTION_MAX_CHARS,
        ]);

    } catch (Throwable $e) {
        response(false, "Could not load tasks.", [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST - create or update
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* A body over post_max_size arrives with $_POST empty and no error, which
       looks exactly like an empty form. Same trap as admin-content.php. */
    if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        response(
            false,
            "Upload is too large for the server (post_max_size is "
                . ini_get("post_max_size") . ").",
            [],
            413
        );
    }

    $id = isset($_POST["id"]) ? (int) $_POST["id"] : 0;

    $existing = null;

    if ($id > 0) {

        $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            response(false, "Task not found.", [], 404);
        }

        // A task never moves between projects, so the form's project_id is
        // ignored on an update - the row's own is the truth.
        $projectId = (int) $existing["project_id"];

    } else {
        $projectId = (int) ($_POST["project_id"] ?? 0);
    }

    if ($projectId <= 0) {
        response(false, "Which project?", [], 400);
    }

    $project = loadScopedProject($pdo, $admin, $projectId);

    if (!$project) {
        response(false, "Project not found.", [], 404);
    }

    if (!canWriteTask($pdo, $admin, $project)) {
        response(false, "You are not on this project's team.", [], 403);
    }

    if ($existing && !canEditTask($admin, $existing)) {
        response(false, "You can only edit your own tasks.", [], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    $title = trim((string) ($_POST["title"] ?? ""));

    if ($title === "") {
        response(false, "Task name is required.", [], 400);
    }

    if (mb_strlen($title) > 200) {
        response(false, "Task name must be 200 characters or fewer.", [], 400);
    }

    /* Printed as an href on the card, so only http(s) - the same block
       admin-content.php uses on content links. */

    $link = trim((string) ($_POST["link"] ?? ""));

    if ($link !== "") {

        if (mb_strlen($link) > 500) {
            response(false, "Link must be 500 characters or fewer.", [], 400);
        }

        if (!preg_match('#^https?://#i', $link)) {
            $link = "https://" . ltrim($link, "/");
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            response(false, "Enter a valid link, e.g. https://example.com", [], 400);
        }
    }

    /* The editor stores HTML but the counter under it counts text, so the cap
       is checked against the text the same way the page measures it. */

    $description = trim((string) ($_POST["description"] ?? ""));

    $plain = trim(html_entity_decode(strip_tags($description), ENT_QUOTES, "UTF-8"));

    if (mb_strlen($plain) > TASK_DESCRIPTION_MAX_CHARS) {
        response(
            false,
            "Description must be " . TASK_DESCRIPTION_MAX_CHARS . " characters or fewer.",
            [],
            400
        );
    }

    $orientation = trim((string) ($_POST["orientation"] ?? "horizontal"));

    if (!in_array($orientation, ["horizontal", "vertical"], true)) {
        $orientation = "horizontal";
    }

    $scheduledDate = validDate($_POST["scheduled_date"] ?? "");
    $scheduledTime = validTime($_POST["scheduled_time"] ?? "");

    if (!empty($_POST["scheduled_date"]) && !$scheduledDate) {
        response(false, "Enter the update date as yyyy-mm-dd.", [], 400);
    }

    if (!empty($_POST["scheduled_time"]) && !$scheduledTime) {
        response(false, "Enter the update time as HH:MM.", [], 400);
    }

    $isComplete = !empty($_POST["is_complete"]) ? 1 : 0;

    /* draft or published, nothing else - anything unrecognised is pinned to
       draft server-side rather than trusted. */
    $status = trim((string) ($_POST["status"] ?? "draft"));

    if (!in_array($status, ["draft", "published"], true)) {
        $status = "draft";
    }

    /*
    |--------------------------------------------------------------------------
    | Image
    |--------------------------------------------------------------------------
    */

    $imagePath   = $existing["image_path"] ?? null;
    $uploaded    = uploadTaskImage($_FILES["image"] ?? null, $uploadDir);
    $removeImage = !empty($_POST["remove_image"]);

    if ($uploaded) {
        $imagePath = $uploaded;
    } elseif ($removeImage) {
        $imagePath = null;
    }

    try {

        if ($id > 0) {

            $stmt = $pdo->prepare("
                UPDATE project_tasks SET
                    title          = ?,
                    link           = ?,
                    description    = ?,
                    orientation    = ?,
                    image_path     = ?,
                    scheduled_date = ?,
                    scheduled_time = ?,
                    is_complete    = ?,
                    status         = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $title,
                $link ?: null,
                $description ?: null,
                $orientation,
                $imagePath,
                $scheduledDate,
                $scheduledTime,
                $isComplete,
                $status,
                $id,
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO project_tasks (
                    project_id,
                    title,
                    link,
                    description,
                    orientation,
                    image_path,
                    scheduled_date,
                    scheduled_time,
                    is_complete,
                    status,
                    created_by_admin_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $projectId,
                $title,
                $link ?: null,
                $description ?: null,
                $orientation,
                $imagePath,
                $scheduledDate,
                $scheduledTime,
                $isComplete,
                $status,
                (int) $admin["id"],
            ]);

            $id = (int) $pdo->lastInsertId();
        }

    } catch (Throwable $e) {

        // The row was never written, so the file just uploaded is an orphan.
        if ($uploaded) {
            deleteTaskImage($uploaded);
        }

        response(false, "Could not save the task.", [], 500);
    }

    // Safe now that the row points somewhere else.
    if (($uploaded || $removeImage) && !empty($existing["image_path"])) {
        if ($existing["image_path"] !== $imagePath) {
            deleteTaskImage($existing["image_path"]);
        }
    }

    /* A published task dated in the future is saved, but the client-facing
       read will skip it until then - so say that rather than "Published". */

    if ($status === "draft") {
        $message = "Task saved as a draft.";
    } elseif ($scheduledDate && $scheduledDate > date("Y-m-d")) {
        $message = "Task scheduled for " . $scheduledDate . ".";
    } else {
        $message = "Task published.";
    }

    /*
    |--------------------------------------------------------------------------
    | Tell the client an update landed
    |--------------------------------------------------------------------------
    |
    | Fires when the task is live now - the same TASK_IS_LIVE_SQL the client
    | endpoint filters on - and has never been announced. The stamp is what
    | makes it happen once: comparing the old status against the new one would
    | re-announce on every later edit.
    |
    | The UPDATE is the test. Claiming the row and reading rowCount() means two
    | concurrent saves cannot both decide they were first, and reusing the
    | constant means the announcement can never disagree with what the client
    | can actually see.
    |
    | The gap, stated plainly: nothing runs on a schedule here, so a task
    | published for a future date is not announced on the day it goes live. It
    | announces itself on the next save that finds it live, and otherwise the
    | client meets it on the page. No notification is lost, only late.
    |
    | This POST runs no transaction, so notify()'s in-transaction guard is
    | satisfied.
    */

    try {

        $stmt = $pdo->prepare("
            UPDATE project_tasks t
            SET t.client_notified_at = NOW()
            WHERE t.id = ?
              AND " . TASK_IS_LIVE_SQL . "
              AND t.client_notified_at IS NULL
        ");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            notify(
                $pdo,
                "user",
                (int) $project["client_id"],
                NOTIFY_PROJECT_UPDATE,
                "New update on " . $project["title"],
                $title,
                "user-project-details.html?id=" . (int) $projectId,
                "admin",
                (int) $admin["id"]
            );
        }

    } catch (Throwable $e) {
        // Same silence as notify() itself: the task is already saved, and a
        // missing client_notified_at column costs a sidebar badge, nothing more.
    }

    response(true, $message, [
        "id"      => $id,
        "status"  => $status,
    ]);
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
|
| DELETE api/project-tasks.php?id=7
|
| What DISCARD TASK calls when the form is editing something that exists.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($_GET["id"] ?? 0);

    if ($id <= 0) {
        $input = json_decode(file_get_contents("php://input"), true) ?? [];
        $id    = (int) ($input["id"] ?? 0);
    }

    if ($id <= 0) {
        response(false, "Which task?", [], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        response(false, "Task not found.", [], 404);
    }

    if (!loadScopedProject($pdo, $admin, $task["project_id"])) {
        response(false, "Task not found.", [], 404);
    }

    if (!canEditTask($admin, $task)) {
        response(false, "You can only delete your own tasks.", [], 403);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM project_tasks WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        response(false, "Could not delete the task.", [], 500);
    }

    deleteTaskImage($task["image_path"] ?? null);

    response(true, "Task deleted.");
}

response(false, "Unsupported request method.", [], 405);
