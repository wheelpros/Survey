<?php

/*
|--------------------------------------------------------------------------
| Public: slides for the login page
|--------------------------------------------------------------------------
|
| login.html is unauthenticated, so this is the one image path in the app
| without a bearer token. It stays deliberately tiny: GET only, four fields,
| no created_by and no timestamps handed to an anonymous caller.
|
| Every failure returns 200 with an empty list. A missing table or a dead DB
| just means the login page keeps its centred form - a normal degraded state,
| not something worth a red line in a visitor's console.
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed.", "slides" => []]);
    exit;
}

$slides = [];

try {

    ensureLoginSlidesTable($pdo);

    $stmt = $pdo->query("
        SELECT id, image_path, title, subtitle
        FROM login_slides
        ORDER BY position ASC, id ASC
        LIMIT " . LOGIN_SLIDES_MAX
    );

    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($slides as &$slide) {
        $slide["id"] = (int) $slide["id"];
    }
    unset($slide);

} catch (Throwable $e) {
    $slides = [];
}

echo json_encode(
    [
        "success" => true,
        "slides"  => $slides
    ],
    JSON_UNESCAPED_UNICODE
);
