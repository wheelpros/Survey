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
require_once "task-writes.php";

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

/* TASK_DESCRIPTION_MAX_CHARS is in task-writes.php, with the rest of what a
   save checks - shared with api/v1. */

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
    // One rule for projects.php, project-tasks.php and api/v1 - see db.php.
    return projectScopeSql($admin);
}

/* loadScopedProject(), canWriteTask() and canEditTask() live in
   task-writes.php, shared with api/v1 so an AI assistant is held to the same
   team rules as this page. */


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

            $canEdit = canEditTask($admin, $task);

            response(true, "Task loaded.", array_merge(taskTeamPayload($pdo, $task["id"]), [
                "task"        => $task,
                "project"     => $project,
                "admin_id"    => (int) $admin["id"],
                "admin_role"  => $admin["role"],
                "can_edit"    => $canEdit,
                // Only the form needs the pool, and only someone who can edit uses it.
                "candidates"  => $canEdit ? taskTeamCandidates($pdo, $project) : [],
                "statuses"    => TASK_ASSIGNMENT_STATUSES,
                "priorities"  => TASK_PRIORITIES,
                "max_bytes"   => TASK_IMAGE_MAX_BYTES,
                "max_chars"   => TASK_DESCRIPTION_MAX_CHARS,
            ]));
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

        $canWrite = canWriteTask($pdo, $admin, $project);

        response(true, "Tasks loaded.", [
            "tasks"       => $stmt->fetchAll(PDO::FETCH_ASSOC),
            "project"     => $project,
            "admin_id"    => (int) $admin["id"],
            "admin_role"  => $admin["role"],
            "can_write"   => $canWrite,
            // The pool a new phase picks its teammates from.
            "candidates"  => $canWrite ? taskTeamCandidates($pdo, $project) : [],
            "statuses"    => TASK_ASSIGNMENT_STATUSES,
            "priorities"  => TASK_PRIORITIES,
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

    /*
    |--------------------------------------------------------------------------
    | Your own box, your own status
    |--------------------------------------------------------------------------
    |
    | set_done ticks the caller's teammate box on a phase; set_my_status moves
    | the caller's own assignment between To do, In progress and Done - from
    | the phase page or the admin dashboard, with no Save. Only the caller's
    | own row changes, so nobody moves someone else's work, and someone not on
    | the phase has no row to move.
    |
    */
    $action = (string) ($_POST["action"] ?? "");

    if ($action === "set_done" || $action === "set_my_status") {

        $taskId = (int) ($_POST["task_id"] ?? 0);

        $stmt = $pdo->prepare("SELECT id, project_id FROM project_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task || !loadScopedProject($pdo, $admin, $task["project_id"])) {
            response(false, "Phase not found.", [], 404);
        }

        try {
            if ($action === "set_done") {
                $done = ($_POST["done"] ?? "") === "1" ? 1 : 0;

                $stmt = $pdo->prepare("
                    UPDATE project_task_teammates
                    SET is_done = ?, done_at = IF(? = 1, NOW(), NULL)
                    WHERE task_id = ? AND admin_id = ?
                ");
                $stmt->execute([$done, $done, $taskId, (int) $admin["id"]]);

                $stmt = $pdo->prepare("SELECT 1 FROM project_task_teammates WHERE task_id = ? AND admin_id = ?");
                $stmt->execute([$taskId, (int) $admin["id"]]);
                if (!$stmt->fetchColumn()) {
                    response(false, "Only a teammate on this phase can tick their box.", [], 403);
                }

                response(true, $done ? "Marked as done." : "Marked as not done.", ["done" => $done]);
            }

            $status = (string) ($_POST["status"] ?? "");
            if (!array_key_exists($status, TASK_ASSIGNMENT_STATUSES)) {
                response(false, "Pick To do, In progress or Done.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT 1 FROM project_task_assignments WHERE task_id = ? AND admin_id = ?");
            $stmt->execute([$taskId, (int) $admin["id"]]);
            if (!$stmt->fetchColumn()) {
                response(false, "Only someone assigned to this phase can change their status.", [], 403);
            }

            $stmt = $pdo->prepare("UPDATE project_task_assignments SET status = ? WHERE task_id = ? AND admin_id = ?");
            $stmt->execute([$status, $taskId, (int) $admin["id"]]);

        } catch (Throwable $e) {
            response(false, "Could not save your status.", [], 500);
        }

        response(true, "Status set to " . TASK_ASSIGNMENT_STATUSES[$status] . ".", ["status" => $status]);
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
    | Dates, before anything is uploaded
    |--------------------------------------------------------------------------
    |
    | The rest of the checks - name, link, description length, status - are
    | saveProjectTask()'s, in task-writes.php, shared with api/v1.
    */

    $scheduledDate = validDate($_POST["scheduled_date"] ?? "");
    $scheduledTime = validTime($_POST["scheduled_time"] ?? "");

    if (!empty($_POST["scheduled_date"]) && !$scheduledDate) {
        response(false, "Enter the update date as yyyy-mm-dd.", [], 400);
    }

    if (!empty($_POST["scheduled_time"]) && !$scheduledTime) {
        response(false, "Enter the update time as HH:MM.", [], 400);
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

    $title = trim((string) ($_POST["title"] ?? ""));
    $status = trim((string) ($_POST["status"] ?? "draft"));

    $fields = [
        "title"          => $title,
        "link"           => $_POST["link"] ?? "",
        "description"    => $_POST["description"] ?? "",
        "orientation"    => trim((string) ($_POST["orientation"] ?? "horizontal")),
        "image_path"     => $imagePath,
        "scheduled_date" => $scheduledDate,
        "scheduled_time" => $scheduledTime,
        "is_complete"    => !empty($_POST["is_complete"]),
        "status"         => $status,
    ];

    // Only the ones the form sent: an absent field is left as it was.
    foreach (["phase_label", "role", "priority", "notes"] as $key) {
        if (array_key_exists($key, $_POST)) {
            $fields[$key] = (string) $_POST[$key];
        }
    }

    /* Both lists come as JSON, so an empty one ("[]") is still sent and still
       means "nobody" - an absent key means "leave the list alone". */
    $teammateIds = isset($_POST["teammates"]) ? json_decode((string) $_POST["teammates"], true) : null;
    $assignments = isset($_POST["assignments"]) ? json_decode((string) $_POST["assignments"], true) : null;

    if ((isset($_POST["teammates"]) && !is_array($teammateIds))
        || (isset($_POST["assignments"]) && !is_array($assignments))) {
        if ($uploaded) {
            deleteTaskImage($uploaded);
        }
        response(false, "The team list could not be read.", [], 400);
    }

    $taskSaved = false;

    try {
        $id = saveProjectTask($pdo, $admin, $project, $existing, $fields);
        $taskSaved = true;

        $newcomers = [];
        if ($teammateIds !== null) {
            $newcomers = saveTaskTeammates($pdo, $admin, $project, $id, $title, $teammateIds);
        }
        if ($assignments !== null) {
            $newcomers = array_merge($newcomers, saveTaskAssignments($pdo, $admin, $project, $id, $title, $assignments));
        }
        notifyAddedToPhase($pdo, $admin, $project, $id, $title, $newcomers);
    } catch (Throwable $e) {
        // The row was never written, so the file just uploaded is an orphan.
        // Once it was, the row points at the file and it has to stay.
        if ($uploaded && !$taskSaved) {
            deleteTaskImage($uploaded);
        }
        $message = $taskSaved
            ? "The phase was saved, but its team was not: "
            : "";
        if ($e instanceof PortalWriteError) {
            response(false, $message . $e->getMessage(), $taskSaved ? ["id" => $id] : [], $e->status === 422 ? 400 : $e->status);
        }
        response(false, $taskSaved ? "The phase was saved, but its team could not be." : "Could not save the task.",
            $taskSaved ? ["id" => $id] : [], 500);
    }

    // Safe now that the row points somewhere else.
    if (($uploaded || $removeImage) && !empty($existing["image_path"])) {
        if ($existing["image_path"] !== $imagePath) {
            deleteTaskImage($existing["image_path"]);
        }
    }

    if (!in_array($status, ["draft", "published"], true)) {
        $status = "draft";
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

    // Told once, the first time it is live - see task-writes.php. This POST
    // runs no transaction, so notify()'s in-transaction guard is satisfied.
    announceTaskIfLive($pdo, $admin, $project, $id, $title);

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

        // The phase's people go with it.
        $pdo->prepare("DELETE FROM project_task_teammates WHERE task_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM project_task_assignments WHERE task_id = ?")->execute([$id]);
    } catch (Throwable $e) {
        response(false, "Could not delete the task.", [], 500);
    }

    deleteTaskImage($task["image_path"] ?? null);

    response(true, "Task deleted.");
}

response(false, "Unsupported request method.", [], 405);
