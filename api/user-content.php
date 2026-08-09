<?php

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Authenticate the portal user
|--------------------------------------------------------------------------
|
| Same guard as api/articles.php: the token must match a users row and the
| account must be approved.
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? $_SERVER["HTTP_AUTHORIZATION"]
    ?? "";

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized access.",
        "content" => []
    ]);
    exit;
}

$token = trim($matches[1]);

try {

    $stmt = $pdo->prepare("
        SELECT id, approved
        FROM users
        WHERE session_token = ?
        LIMIT 1
    ");

    $stmt->execute([$token]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int) $user["approved"] !== 1) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Invalid session token.",
            "content" => []
        ]);
        exit;
    }

} catch (Throwable $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Authentication database error.",
        "content" => []
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Published content
|--------------------------------------------------------------------------
|
| Every approved user sees the same list. Drafts and scheduled posts stay
| out of it entirely.
|
| `created_by` holds an admins.id, but content.html prints the value straight
| into the "Created by" field - so resolve it to a name here, exactly as
| api/admin-content.php does.
|
*/

try {

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

        WHERE c.status = 'published'

        ORDER BY c.created_at DESC
    ");

    echo json_encode([
        "success" => true,
        "content" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to load content.",
        "content" => []
    ]);
}
