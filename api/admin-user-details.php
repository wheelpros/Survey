<?php

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

function respond($success, $message = "", $extra = [], $code = 200)
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
| Authenticate the admin
|--------------------------------------------------------------------------
|
| Any admin may view a user. Clients Management itself is open to every admin,
| so narrowing here would load the page and then 401 the fetch.
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? $_SERVER["HTTP_AUTHORIZATION"]
    ?? "";

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    respond(false, "Unauthorized.", [], 401);
}

try {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([trim($matches[1])]);

    if (!$stmt->fetch()) {
        respond(false, "Unauthorized.", [], 401);
    }
} catch (Throwable $e) {
    respond(false, "Authentication database error.", [], 500);
}

/*
|--------------------------------------------------------------------------
| The user
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id"]) || !ctype_digit((string) $_GET["id"])) {
    respond(false, "A numeric user id is required.", [], 400);
}

$userId = (int) $_GET["id"];

// company_name, website, description and phone are added lazily by db.php.
ensureUserProfileColumns($pdo);

try {
    // Explicit columns: never expose password or session_token.
    $stmt = $pdo->prepare("
        SELECT
            id, name, email, phone,
            company_name, website, description,
            profile_image, approved, created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    respond(false, "Could not load the user.", [], 500);
}

if (!$user) {
    respond(false, "User not found.", [], 404);
}

/*
|--------------------------------------------------------------------------
| Activity
|--------------------------------------------------------------------------
|
| Each list is the query the matching admin page already runs, narrowed to
| this user. They are wrapped individually so a deploy missing one table
| still renders the profile instead of failing the whole request.
|
*/

function fetchList(PDO $pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$surveys = fetchList($pdo, "
    SELECT id, title, status, created_at
    FROM surveys
    WHERE assigned_user_id = ?
    ORDER BY created_at DESC
", [$userId]);

$responses = fetchList($pdo, "
    SELECT id, survey_id, survey_title, submitted_at
    FROM survey_responses
    WHERE user_id = ?
    ORDER BY id DESC
", [$userId]);

$appointments = fetchList($pdo, "
    SELECT a.id, a.title, a.topic, a.notes, a.date, a.time, a.status,
           a.requested_by, a.client, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    WHERE a.user_id = ?
    ORDER BY a.date DESC, a.time DESC
", [$userId]);

$files = fetchList($pdo, "
    SELECT sf.id, sf.original_name, sf.file_size, sf.uploaded_at
    FROM user_source_files usf
    JOIN source_files sf ON sf.id = usf.file_id
    WHERE usf.user_id = ?
    ORDER BY usf.assigned_at DESC
", [$userId]);

/*
| Content this user can see: public posts plus anything targeted at their
| client. Same rule as api/user-content.php - this is the answer to "why does
| this user see that post?".
*/
$company = trim($user["company_name"] ?? "");

$content = fetchList($pdo, "
    SELECT id, title, platform, type_label, client, status, created_at
    FROM content
    WHERE status = 'published'
      AND (client IS NULL OR client = '' OR (? <> '' AND client = ?))
    ORDER BY created_at DESC
", [$company, $company]);

/*
| The SEO admin managing this user, if any.
*/
$seoAdmin = null;

$managing = fetchList($pdo, "
    SELECT a.id, a.name, a.email
    FROM admin_user_assignments aua
    JOIN admins a ON a.id = aua.admin_id
    WHERE aua.user_id = ?
    LIMIT 1
", [$userId]);

if ($managing) {
    $seoAdmin = $managing[0];
}

respond(true, "User loaded.", [
    "user"         => $user,
    "seoAdmin"     => $seoAdmin,
    "surveys"      => $surveys,
    "responses"    => $responses,
    "appointments" => $appointments,
    "files"        => $files,
    "content"      => $content,
]);
