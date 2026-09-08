<?php

/*
|--------------------------------------------------------------------------
| Projects: the engagements Project Management is built on
|--------------------------------------------------------------------------
|
| Backs project-management.html (the list), project-details.html (one project
| with its tasks) and project-form.html (create and edit). Tasks live next
| door in api/project-tasks.php - separate file because it has its own upload
| directory, size cap and role gates.
|
| Routed on REQUEST_METHOD like admin-content.php rather than ?action= like
| calendar.php, for one mechanical reason: the form posts an image, so the body
| is multipart/form-data, and PHP does not populate $_POST or $_FILES on PUT.
| Create versus update is therefore decided by the presence of `id` in a POST.
|
| sql/projects.sql is the schema, and carries the long explanation of why
| `status` and the four stat cards are separate ideas.
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

/* The vocabularies - PROJECT_TYPES and PROJECT_STATUSES - live in db.php
   beside ensureProjectTables(), because api/user-projects.php needs them too
   and cannot require_once this file: it runs its auth block at top level. */

const PROJECT_IMAGE_MAX_BYTES = 3 * 1024 * 1024;

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

/*
|--------------------------------------------------------------------------
| No blanket role gate
|--------------------------------------------------------------------------
|
| calendar.php closes its whole file to seo_admin. This one does not, because
| an seo_admin can be a team member on a project and has to be able to open it.
| Scope does the work instead - see projectScope() - and each write below gates
| itself.
|
*/

ensureProjectTables($pdo);
ensureNotificationsTable($pdo);

/*
|--------------------------------------------------------------------------
| Which projects this admin may see
|--------------------------------------------------------------------------
|
| Returns [$sqlFragment, $params] so the list, the stats, the single read and
| every write guard use literally the same predicate. The owner short-circuits
| to no WHERE at all, exactly as everywhere else in this codebase.
|
| Three ways in for everybody else. The first is the established rule - the
| clients assigned to you in admin_user_assignments. The other two are the
| widening this module needs: the project you manage, and the project you are
| a member of. Without them TEAM MEMBERS would be decoration, since the people
| put on a project could not open it.
|
| Assumes the projects table is aliased `p`.
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
| The bucket every stat card counts, computed once in SQL so the page never
| re-derives the rule in JS and drifts from the cards.
*/
const PROJECT_BUCKET_SQL = "
    CASE
        WHEN p.status = 'completed'    THEN 'completed'
        WHEN p.start_date > CURDATE()  THEN 'upcoming'
        ELSE 'active'
    END
";

/*
|--------------------------------------------------------------------------
| Who may create, edit, delete
|--------------------------------------------------------------------------
|
| Creating an engagement is not an seo_admin's job - that role sits at the
| bottom of the assignment chain in admin-user-assignments.php and works tasks.
|
| Editing is canManageContent()'s rule widened by one clause: the account
| manager of a project may edit it. The details panel calls them the MANAGER,
| so being unable to touch the thing would be absurd.
|
| Deleting stays narrower than editing on purpose - a manager can correct a
| project, only the owner or whoever created it can destroy it and its tasks.
|
*/

function canCreateProject(array $admin)
{
    return in_array(
        $admin["role"] ?? "",
        ["owner", "super_admin", "account_manager"],
        true
    );
}

function canManageProject(array $admin, array $project)
{
    if (($admin["role"] ?? "") === "owner") {
        return true;
    }

    $adminId = (int) $admin["id"];

    return (int) $project["created_by_admin_id"] === $adminId
        || (int) $project["account_manager_admin_id"] === $adminId;
}

function canDeleteProject(array $admin, array $project)
{
    if (($admin["role"] ?? "") === "owner") {
        return true;
    }

    return (int) $project["created_by_admin_id"] === (int) $admin["id"];
}

/*
|--------------------------------------------------------------------------
| Which clients an admin may act on
|--------------------------------------------------------------------------
|
| Copied from calendar.php, where the same query serves the same purpose: the
| owner sees every client, everyone else only the ones assigned to them. Feeds
| both the "All clients" filter on the list and the CLIENT NAME select on the
| form, so the create page needs no second request.
|
*/

function scopedClients(PDO $pdo, array $admin)
{
    if (($admin["role"] ?? "") === "owner") {
        $stmt = $pdo->query("
            SELECT id, name, email, company_name
            FROM users
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$admin["id"]]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Everything the project form's selects need
|--------------------------------------------------------------------------
|
| Returned with both the list and the single project, so project-form.html
| fills itself from one request whether it is creating or editing.
|
| The admin lists deliberately do not come from get-admins.php. That endpoint
| answers a different question - "which admins may this caller manage" - so an
| account_manager calling it gets back only the seo_admins reporting to them
| and no account managers at all, which would leave the MANAGER select empty
| for exactly the role that uses this page most.
|
| `managers` is the same set the POST validates against, so the form can never
| offer a choice the save would reject.
|
*/

function formOptions(PDO $pdo, array $admin)
{
    $types = [];
    foreach (PROJECT_TYPES as $id => $label) {
        $types[] = ["id" => $id, "label" => $label];
    }

    $statuses = [];
    foreach (PROJECT_STATUSES as $id => $label) {
        $statuses[] = ["id" => $id, "label" => $label];
    }

    $managers = $pdo->query("
        SELECT id, name, email, role
        FROM admins
        WHERE active = 1 AND role IN ('owner', 'account_manager')
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Anybody with an account can be put on a team - that is what membership
       is for. Who may then create or publish is decided at write time. */
    $admins = $pdo->query("
        SELECT id, name, email, role
        FROM admins
        WHERE active = 1
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return [
        "clients"  => scopedClients($pdo, $admin),
        "types"    => $types,
        "statuses" => $statuses,
        "managers" => $managers,
        "admins"   => $admins,
    ];
}

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDir = dirname(__DIR__) . "/uploads/projects/";

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
| Copied from deleteContentImage() in admin-content.php. Copied rather than
| shared because the upload helper below calls this file's own response(), the
| same reason announcements.php keeps its own pair.
|
*/

function deleteProjectImage($relativePath)
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
| uploadContentImage() from admin-content.php, with the announcements.php
| refinement: an empty file input returns null instead of an error, so the
| caller does not have to guess whether one was sent.
|
| The MIME type is sniffed from the file itself, never taken from the name or
| the browser's claim, and the stored name is random so nothing is guessable
| or overwritable.
|
*/

function uploadProjectImage($file, $uploadDir)
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

    if (($file["size"] ?? 0) > PROJECT_IMAGE_MAX_BYTES) {
        response(false, "Image must be 3 MB or smaller.", [], 400);
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

    return "uploads/projects/" . $filename;
}

/*
|--------------------------------------------------------------------------
| Helper: read one project, scoped
|--------------------------------------------------------------------------
|
| Returns null when the row does not exist OR is out of scope - the caller
| answers 404 for both, so the response never confirms that a project it
| cannot see is really there. admin-content.php does the same.
|
*/

function loadProject(PDO $pdo, array $admin, $id)
{
    [$scopeSql, $scopeParams] = projectScope($admin);

    $sql = "
        SELECT
            p.*,
            " . PROJECT_BUCKET_SQL . " AS bucket,
            u.name         AS client_name,
            u.email        AS client_email,
            u.company_name AS company_name,
            m.name         AS account_manager_name,
            m.email        AS account_manager_email,
            c.name         AS created_by_name,
            (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS task_count
        FROM projects p
        LEFT JOIN users  u ON u.id = p.client_id
        LEFT JOIN admins m ON m.id = p.account_manager_admin_id
        LEFT JOIN admins c ON c.id = p.created_by_admin_id
        WHERE p.id = ?
    ";

    $params = [(int) $id];

    if ($scopeSql !== "") {
        $sql .= " AND " . $scopeSql;
        $params = array_merge($params, $scopeParams);
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        return null;
    }

    $project["type_label"]   = PROJECT_TYPES[$project["project_type"]] ?? $project["project_type"];
    $project["status_label"] = PROJECT_STATUSES[$project["status"]] ?? $project["status"];

    return $project;
}

/*
| The date fields arrive as yyyy-mm-dd from a native date input. Anything else
| is a client that has been tampered with, so it is a 400 rather than a silent
| NULL. announcements.php validates the same way.
*/

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

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| GET api/projects.php            the list, its stats and the filter options
| GET api/projects.php?id=42      one project with its tasks and members
|
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        /*
        |----------------------------------------------------------------------
        | One project: everything project-details.html needs, in one round trip
        |----------------------------------------------------------------------
        |
        | The month grid, the three summary tiles, the task cards and their
        | pager are all painted from this single payload. Paging the month is
        | pure client state, so there is no ?month= endpoint - a project spans
        | a quarter and that is a few dozen rows.
        |
        */

        if (isset($_GET["id"])) {

            $project = loadProject($pdo, $admin, $_GET["id"]);

            if (!$project) {
                response(false, "Project not found.", [], 404);
            }

            /*
            | Draft visibility: the owner, the account managers and this
            | project's own manager see every task. Everybody else sees the
            | published ones plus their own drafts - a half-written update is
            | not a teammate's business.
            */

            $seesEveryDraft =
                in_array($admin["role"], ["owner", "account_manager"], true)
                || (int) $project["account_manager_admin_id"] === (int) $admin["id"];

            $taskSql = "
                SELECT
                    t.*,
                    a.name AS created_by_name,
                    " . TASK_IS_LIVE_SQL . " AS is_live
                FROM project_tasks t
                LEFT JOIN admins a ON a.id = t.created_by_admin_id
                WHERE t.project_id = ?
            ";

            $taskParams = [(int) $project["id"]];

            if (!$seesEveryDraft) {
                $taskSql .= " AND (t.status <> 'draft' OR t.created_by_admin_id = ?)";
                $taskParams[] = (int) $admin["id"];
            }

            $taskSql .= " ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id DESC";

            $stmt = $pdo->prepare($taskSql);
            $stmt->execute($taskParams);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT m.admin_id, a.name, a.email, a.role
                FROM project_members m
                LEFT JOIN admins a ON a.id = m.admin_id
                WHERE m.project_id = ?
                ORDER BY a.name ASC
            ");
            $stmt->execute([(int) $project["id"]]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $isMember = false;

            foreach ($members as $member) {
                if ((int) $member["admin_id"] === (int) $admin["id"]) {
                    $isMember = true;
                    break;
                }
            }

            response(true, "Project loaded.", array_merge([
                "project"    => $project,
                "tasks"      => $tasks,
                "members"    => $members,
                "admin_id"   => (int) $admin["id"],
                "admin_role" => $admin["role"],
                "can_edit"   => canManageProject($admin, $project),
                "can_delete" => canDeleteProject($admin, $project),
                "is_member"  => $isMember,
            ], formOptions($pdo, $admin)));
        }

        /*
        |----------------------------------------------------------------------
        | The list
        |----------------------------------------------------------------------
        |
        | Returned unpaged. Every list in this portal pages client-side -
        | PAGE_SIZE 6 in admin-content.html, 5 in admin-calendar.html - so
        | "Showing 1 to 4 of 24" and the numbered pager are computed in JS.
        | The filters are accepted here anyway so that adding a LIMIT later is
        | an additive change rather than a rewrite.
        |
        */

        [$scopeSql, $scopeParams] = projectScope($admin);

        $where  = [];
        $params = [];

        if ($scopeSql !== "") {
            $where[]  = $scopeSql;
            $params   = array_merge($params, $scopeParams);
        }

        $search = trim((string) ($_GET["search"] ?? ""));

        if ($search !== "") {
            $where[]  = "(p.title LIKE ? OR u.company_name LIKE ? OR u.name LIKE ?)";
            $like     = "%" . $search . "%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($_GET["client_id"])) {
            $where[]  = "p.client_id = ?";
            $params[] = (int) $_GET["client_id"];
        }

        if (!empty($_GET["type"]) && isset(PROJECT_TYPES[$_GET["type"]])) {
            $where[]  = "p.project_type = ?";
            $params[] = $_GET["type"];
        }

        if (!empty($_GET["status"]) && isset(PROJECT_STATUSES[$_GET["status"]])) {
            $where[]  = "p.status = ?";
            $params[] = $_GET["status"];
        }

        $whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

        $stmt = $pdo->prepare("
            SELECT
                p.*,
                " . PROJECT_BUCKET_SQL . " AS bucket,
                u.name         AS client_name,
                u.company_name AS company_name,
                m.name         AS account_manager_name,
                (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS task_count
            FROM projects p
            LEFT JOIN users  u ON u.id = p.client_id
            LEFT JOIN admins m ON m.id = p.account_manager_admin_id
            $whereSql
            ORDER BY p.start_date DESC, p.id DESC
        ");
        $stmt->execute($params);

        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($projects as &$row) {
            $row["type_label"]   = PROJECT_TYPES[$row["project_type"]] ?? $row["project_type"];
            $row["status_label"] = PROJECT_STATUSES[$row["status"]] ?? $row["status"];
        }
        unset($row);

        /*
        | The cards are counted in SQL over the SCOPE only, deliberately
        | ignoring the filter row: they are a summary of the whole book of
        | work, while the footer under the table describes what the table is
        | currently showing. The two numbers are meant to diverge once a filter
        | is applied.
        */

        $statsWhere  = [];
        $statsParams = [];

        if ($scopeSql !== "") {
            $statsWhere[]  = $scopeSql;
            $statsParams   = $scopeParams;
        }

        $statsWhereSql = $statsWhere ? (" WHERE " . implode(" AND ", $statsWhere)) : "";

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                   AS total,
                SUM(p.status =  'completed')                               AS completed,
                SUM(p.status <> 'completed' AND p.start_date >  CURDATE()) AS upcoming,
                SUM(p.status <> 'completed' AND p.start_date <= CURDATE()) AS active
            FROM projects p
            $statsWhereSql
        ");
        $stmt->execute($statsParams);

        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        response(true, "Projects loaded.", array_merge([
            "projects"   => $projects,
            "stats"      => [
                "total"     => (int) ($stats["total"] ?? 0),
                "active"    => (int) ($stats["active"] ?? 0),
                "completed" => (int) ($stats["completed"] ?? 0),
                "upcoming"  => (int) ($stats["upcoming"] ?? 0),
            ],
            "admin_id"   => (int) $admin["id"],
            "admin_role" => $admin["role"],
            "can_create" => canCreateProject($admin),
        ], formOptions($pdo, $admin)));

    } catch (Throwable $e) {
        response(false, "Could not load projects.", [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST - create or update
|--------------------------------------------------------------------------
|
| multipart/form-data, because of the image. No `id` means create.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    | A body larger than post_max_size arrives with $_POST empty and no PHP
    | error, which looks exactly like an empty form. Say what actually
    | happened. Same trap as admin-content.php.
    */
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

        $existing = loadProject($pdo, $admin, $id);

        if (!$existing) {
            response(false, "Project not found.", [], 404);
        }

        if (!canManageProject($admin, $existing)) {
            response(false, "You do not have permission to edit this project.", [], 403);
        }

    } elseif (!canCreateProject($admin)) {
        response(false, "You do not have permission to create projects.", [], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    $title = trim((string) ($_POST["title"] ?? ""));

    if ($title === "") {
        response(false, "Project title is required.", [], 400);
    }

    if (mb_strlen($title) > 200) {
        response(false, "Project title must be 200 characters or fewer.", [], 400);
    }

    $description = trim((string) ($_POST["description"] ?? ""));

    if (mb_strlen($description) > 5000) {
        response(false, "Description must be 5000 characters or fewer.", [], 400);
    }

    $clientId = (int) ($_POST["client_id"] ?? 0);

    if ($clientId <= 0) {
        response(false, "Choose a client for this project.", [], 400);
    }

    /* The client has to be one this admin actually reaches, or an assigned
       admin could attach a project to somebody else's client by id. */
    $clientOk = false;

    foreach (scopedClients($pdo, $admin) as $client) {
        if ((int) $client["id"] === $clientId) {
            $clientOk = true;
            break;
        }
    }

    if (!$clientOk) {
        response(false, "That client is not available to you.", [], 403);
    }

    $projectType = trim((string) ($_POST["project_type"] ?? "survey"));

    if (!isset(PROJECT_TYPES[$projectType])) {
        response(false, "Choose a valid project type.", [], 400);
    }

    $status = trim((string) ($_POST["status"] ?? "planning"));

    if (!isset(PROJECT_STATUSES[$status])) {
        response(false, "Choose a valid project status.", [], 400);
    }

    $startDate = validDate($_POST["start_date"] ?? "");
    $endDate   = validDate($_POST["end_date"] ?? "");

    if (!$startDate || !$endDate) {
        response(false, "A start date and an end date are both required.", [], 400);
    }

    if ($endDate < $startDate) {
        response(false, "The end date cannot be before the start date.", [], 400);
    }

    /* Clamped rather than rejected: the slider cannot produce anything else,
       so a value outside the range is noise, not a mistake worth a message. */
    $progress = max(0, min(100, (int) ($_POST["progress"] ?? 0)));

    $managerId = (int) ($_POST["account_manager_admin_id"] ?? 0);

    if ($managerId > 0) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM admins
            WHERE id = ?
              AND active = 1
              AND role IN ('owner', 'account_manager')
            LIMIT 1
        ");
        $stmt->execute([$managerId]);

        if (!$stmt->fetch()) {
            response(false, "The account manager must be an active account manager.", [], 400);
        }
    }

    /*
    | Sent either as repeated member_ids[] fields or as one comma-separated
    | string, because a plain form and a FormData build it differently.
    | announcements.php accepts recipient_ids the same way.
    */
    $memberIds = $_POST["member_ids"] ?? [];

    if (!is_array($memberIds)) {
        $memberIds = array_filter(explode(",", (string) $memberIds), "strlen");
    }

    $memberIds = array_values(array_unique(array_filter(
        array_map("intval", $memberIds),
        function ($value) {
            return $value > 0;
        }
    )));

    if ($memberIds) {

        $placeholders = implode(",", array_fill(0, count($memberIds), "?"));

        $stmt = $pdo->prepare("
            SELECT id FROM admins WHERE id IN ($placeholders) AND active = 1
        ");
        $stmt->execute($memberIds);

        $memberIds = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /*
    |--------------------------------------------------------------------------
    | Image
    |--------------------------------------------------------------------------
    |
    | Written before the row so a failed upload never leaves a project pointing
    | at a file that was never saved. The old file is removed only after the
    | row is safely updated, further down.
    |
    */

    $imagePath   = $existing["image_path"] ?? null;
    $uploaded    = uploadProjectImage($_FILES["image"] ?? null, $uploadDir);
    $removeImage = !empty($_POST["remove_image"]);

    if ($uploaded) {
        $imagePath = $uploaded;
    } elseif ($removeImage) {
        $imagePath = null;
    }

    try {

        $pdo->beginTransaction();

        if ($id > 0) {

            $stmt = $pdo->prepare("
                UPDATE projects SET
                    client_id                = ?,
                    title                    = ?,
                    description              = ?,
                    project_type             = ?,
                    start_date               = ?,
                    end_date                 = ?,
                    progress                 = ?,
                    status                   = ?,
                    image_path               = ?,
                    account_manager_admin_id = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $clientId,
                $title,
                $description ?: null,
                $projectType,
                $startDate,
                $endDate,
                $progress,
                $status,
                $imagePath,
                $managerId ?: null,
                $id,
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO projects (
                    client_id,
                    title,
                    description,
                    project_type,
                    start_date,
                    end_date,
                    progress,
                    status,
                    image_path,
                    account_manager_admin_id,
                    created_by_admin_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $clientId,
                $title,
                $description ?: null,
                $projectType,
                $startDate,
                $endDate,
                $progress,
                $status,
                $imagePath,
                $managerId ?: null,
                (int) $admin["id"],
            ]);

            $id = (int) $pdo->lastInsertId();
        }

        /* Full replace, the shape admin-user-assignments.php uses: the form
           always posts the complete team, so anything missing was removed. */

        $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ?");
        $stmt->execute([$id]);

        if ($memberIds) {

            $stmt = $pdo->prepare("
                INSERT INTO project_members (project_id, admin_id) VALUES (?, ?)
            ");

            foreach ($memberIds as $memberId) {
                $stmt->execute([$id, $memberId]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // The row was never written, so the file just uploaded is an orphan.
        if ($uploaded) {
            deleteProjectImage($uploaded);
        }

        response(false, "Could not save the project.", [], 500);
    }

    // Safe now that the row is committed and pointing somewhere else.
    if (($uploaded || $removeImage) && !empty($existing["image_path"])) {
        if ($existing["image_path"] !== $imagePath) {
            deleteProjectImage($existing["image_path"]);
        }
    }

    /*
    | Outside the transaction, per rule 2 in notify.php. Everybody put on the
    | project hears about it once - the team plus the manager, minus whoever
    | did it, who does not need telling.
    */

    $recipients = $memberIds;

    if ($managerId > 0) {
        $recipients[] = $managerId;
    }

    foreach (array_unique($recipients) as $recipientId) {

        if ((int) $recipientId === (int) $admin["id"]) {
            continue;
        }

        notify(
            $pdo,
            "admin",
            (int) $recipientId,
            NOTIFY_PROJECT_ASSIGNED,
            "You were added to a project",
            $title,
            "project-details.html?id=" . $id,
            "admin",
            (int) $admin["id"]
        );
    }

    response(true, $existing ? "Project updated." : "Project created.", ["id" => $id]);
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
|
| DELETE api/projects.php?id=42
|
| There are no foreign keys, so the tasks and the team rows are cleared by
| hand. The files go last, after the commit: a rollback must never leave the
| database pointing at an image that has already been deleted.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($_GET["id"] ?? 0);

    if ($id <= 0) {
        $input = json_decode(file_get_contents("php://input"), true) ?? [];
        $id    = (int) ($input["id"] ?? 0);
    }

    if ($id <= 0) {
        response(false, "Which project?", [], 400);
    }

    $project = loadProject($pdo, $admin, $id);

    if (!$project) {
        response(false, "Project not found.", [], 404);
    }

    if (!canDeleteProject($admin, $project)) {
        response(false, "You do not have permission to delete this project.", [], 403);
    }

    $stmt = $pdo->prepare("
        SELECT image_path FROM project_tasks WHERE project_id = ? AND image_path IS NOT NULL
    ");
    $stmt->execute([$id]);

    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($project["image_path"])) {
        $files[] = $project["image_path"];
    }

    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM project_tasks WHERE project_id = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        response(false, "Could not delete the project.", [], 500);
    }

    foreach ($files as $file) {
        deleteProjectImage($file);
    }

    response(true, "Project deleted.");
}

response(false, "Unsupported request method.", [], 405);
