<?php

/*
|--------------------------------------------------------------------------
| Notifications: reading them
|--------------------------------------------------------------------------
|
| Backs notifications.html and admin-notifications.html, plus the unread
| badge that assets/notifications.js paints into every sidebar.
|
| One endpoint for both audiences, the way calendar.php does it: a Bearer
| token is resolved against `users` first and `admins` second, and whichever
| matched becomes the recipient every query below is scoped to. There is no
| way to read another recipient's rows - `recipient_kind` and `recipient_id`
| come from the token, never from the request.
|
| Writing them is api/notify.php; the table is sql/notifications.sql.
|
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";

function reply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Who is calling
|--------------------------------------------------------------------------
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? "";

if (!$authHeader && isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authHeader = $_SERVER["HTTP_AUTHORIZATION"];
}

$token = trim(str_replace("Bearer", "", $authHeader));

if (!$token) {
    reply(false, "No token provided");
}

ensureNotificationsTable($pdo);

$stmt = $pdo->prepare("SELECT id FROM users WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$admin = null;

if (!$user) {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        reply(false, "Unauthorized token");
    }
}

$kind = $user ? "user" : "admin";
$me   = (int) ($user ? $user["id"] : $admin["id"]);

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$input = json_decode(file_get_contents("php://input"), true) ?? [];

/** How many of this recipient's rows are still unread. */
function unreadCount(PDO $pdo, string $kind, int $me)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL
    ");
    $stmt->execute([$kind, $me]);

    return (int) $stmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

if ($action === "count") {
    reply(true, "", ["unread" => unreadCount($pdo, $kind, $me)]);
}

if ($action === "list") {

    $limit = (int) ($_GET["limit"] ?? 25);
    $limit = max(1, min(50, $limit));

    // Keyset paging on the primary key, not OFFSET: a fan-out writes several
    // rows inside the same second, so created_at cannot order them stably.
    $before = (int) ($_GET["before"] ?? 0);

    $sql = "
        SELECT id, event_type, title, body, link, read_at,
               created_at, UNIX_TIMESTAMP(created_at) AS created_ts
        FROM notifications
        WHERE recipient_kind = ? AND recipient_id = ?
    ";
    $params = [$kind, $me];

    if ($before > 0) {
        $sql .= " AND id < ?";
        $params[] = $before;
    }

    // One more than asked for, so the extra row answers "is there another
    // page" without a second COUNT.
    $sql .= " ORDER BY id DESC LIMIT " . ($limit + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;

    if ($hasMore) {
        array_pop($rows);
    }

    $notifications = array_map(function ($row) {
        return [
            "id"         => (int) $row["id"],
            "event_type" => $row["event_type"],
            "title"      => $row["title"],
            "body"       => $row["body"],
            "link"       => $row["link"],
            "read"       => $row["read_at"] !== null,
            "created_at" => $row["created_at"],
            // MySQL's CURRENT_TIMESTAMP is server-local, and a bare
            // "Y-m-d H:i:s" parsed in a browser is read as browser-local. The
            // epoch is the only version both ends agree on.
            "created_ts" => (int) $row["created_ts"],
        ];
    }, $rows);

    reply(true, "", [
        "notifications" => $notifications,
        "unread"        => unreadCount($pdo, $kind, $me),
        "has_more"      => $hasMore,
    ]);
}

/*
|--------------------------------------------------------------------------
| Marking read
|--------------------------------------------------------------------------
|
| The recipient predicate is the authorisation - an id belonging to somebody
| else simply matches no rows.
|
*/

if ($action === "mark_read") {

    $id = (int) ($input["id"] ?? $_POST["id"] ?? 0);

    if ($id <= 0) {
        reply(false, "A notification id is required");
    }

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET read_at = NOW()
        WHERE id = ? AND recipient_kind = ? AND recipient_id = ? AND read_at IS NULL
    ");
    $stmt->execute([$id, $kind, $me]);

    reply(true, "", ["unread" => unreadCount($pdo, $kind, $me)]);
}

if ($action === "mark_all_read") {

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET read_at = NOW()
        WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL
    ");
    $stmt->execute([$kind, $me]);

    reply(true, "All notifications marked as read", ["unread" => 0]);
}

reply(false, "Unknown action");
