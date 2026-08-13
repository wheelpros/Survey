<?php

/*
|--------------------------------------------------------------------------
| Custom content types
|--------------------------------------------------------------------------
|
| GET  api/content-types.php          -> every saved custom type
| POST api/content-types.php          -> add one (label, platform, category)
|
| The six built-in types live in admin-content-form.html. Anything an admin
| adds with the "+" chip is stored here so it is offered again the next time
| someone creates content, instead of having to be re-typed.
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

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
| Authenticate the admin
|--------------------------------------------------------------------------
|
| Same guard as api/admin-content.php: resolving the bearer token against the
| admins table is the authorisation, no role narrowing.
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? $_SERVER["HTTP_AUTHORIZATION"]
    ?? "";

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    response(false, "Unauthorized.", ["types" => []], 401);
}

$token = trim($matches[1]);

try {

    $stmt = $pdo->prepare("
        SELECT id, name, role
        FROM admins
        WHERE session_token = ?
        LIMIT 1
    ");

    $stmt->execute([$token]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        response(false, "Invalid or expired admin session.", ["types" => []], 401);
    }

} catch (Throwable $e) {
    response(false, "Authentication database error.", ["types" => []], 500);
}

ensureContentTypesTable($pdo);

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        $stmt = $pdo->query("
            SELECT type_id, label, platform, category, icon
            FROM content_types
            ORDER BY label ASC
        ");

        response(
            true,
            "Types loaded.",
            [
                "types" => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]
        );

    } catch (Throwable $e) {

        // The table may not exist yet on a read-only DB user. An empty list
        // still leaves the built-in types usable, so this is not an error the
        // form needs to show.
        response(true, "No saved types.", ["types" => []]);
    }
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = $_POST;

    if (!$input) {
        $input = json_decode(file_get_contents("php://input"), true) ?: [];
    }

    $label = trim($input["label"] ?? "");
    $platform = trim($input["platform"] ?? "");

    /*
    | The form does not ask for an icon; a custom type gets the generic chip.
    | The column stays because types saved when the picker existed still carry
    | one, and a caller may still send a valid value.
    */
    $icons = ["camera", "reel", "mail", "case", "ad", "chart"];

    $icon = trim($input["icon"] ?? "");

    if (!in_array($icon, $icons, true)) {
        $icon = "plus";
    }

    /*
    | The category groups types. The form no longer asks for one - the platform
    | is the useful grouping, so it doubles as the category unless a caller
    | sends its own.
    */
    $category = trim($input["category"] ?? "");

    if (!$label || !$platform) {
        response(false, "A type needs a name and a platform.", [], 400);
    }

    if ($category === "") {
        $category = $platform;
    }

    if (
        mb_strlen($label) > 80
        || mb_strlen($platform) > 60
        || mb_strlen($category) > 60
    ) {
        response(false, "Type name, platform or category is too long.", [], 400);
    }

    /*
    | Same id shape the form used to build in memory, so posts saved before
    | this endpoint existed still match a saved type.
    |
    | A label with no a-z0-9 in it at all (an Arabic name, say) slugs to
    | nothing, so fall back to a hash of the label - still stable, so the same
    | name always maps to the same type.
    */
    $slug = trim(
        preg_replace('/[^a-z0-9]+/', "_", mb_strtolower($label)),
        "_"
    );

    if ($slug === "") {
        $slug = substr(md5($label), 0, 10);
    }

    $typeId = "custom_" . $slug;

    try {

        $stmt = $pdo->prepare("
            SELECT type_id, label, platform, category, icon
            FROM content_types
            WHERE type_id = ?
            LIMIT 1
        ");

        $stmt->execute([$typeId]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            response(false, "That type already exists.", ["type" => $existing], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO content_types (type_id, label, platform, category, icon, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$typeId, $label, $platform, $category, $icon, $admin["id"]]);

        response(
            true,
            "Type saved.",
            [
                "type" => [
                    "type_id"  => $typeId,
                    "label"    => $label,
                    "platform" => $platform,
                    "category" => $category,
                    "icon"     => $icon
                ]
            ]
        );

    } catch (Throwable $e) {

        response(false, "Could not save the type.", [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $typeId = trim($_GET["type_id"] ?? "");

    if (!$typeId) {
        $input = json_decode(file_get_contents("php://input"), true) ?: [];
        $typeId = trim($input["type_id"] ?? "");
    }

    if (!$typeId) {
        response(false, "Invalid type.", [], 400);
    }

    try {

        $stmt = $pdo->prepare("DELETE FROM content_types WHERE type_id = ?");
        $stmt->execute([$typeId]);

        response(true, "Type removed.");

    } catch (Throwable $e) {
        response(false, "Could not remove the type.", [], 500);
    }
}

response(false, "Method not allowed.", [], 405);
