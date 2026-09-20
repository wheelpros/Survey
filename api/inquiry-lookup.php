<?php

/*
|--------------------------------------------------------------------------
| Read-only inquiry lookup - the AI-facing sibling of public-inquiry.php
|--------------------------------------------------------------------------
|
| public-inquiry.php mixes the public GET (show the form) with the POST
| (submit an answer) and legacy invite-token compatibility. That's fine for
| the form page itself, but it's not something external callers - MCP
| server included - should depend on directly, since that logic is free to
| change shape for reasons that have nothing to do with this.
|
| This file exposes exactly the same thing an anonymous visitor already
| sees, nothing more:
|
|   GET /api/inquiry-lookup.php?name=<slug>
|     -> { success, inquiry: { title, intro_text, status }, fields: [...] }
|
| Deliberately absent:
|   - No POST / answer submission (that stays in public-inquiry.php only).
|   - No "list all inquiries" mode. An inquiry is only reachable by
|     already knowing its exact name, same boundary the public form has -
|     adding enumeration would expose something that isn't already public.
|   - No internal ids, account_manager_admin_id, reference, or
|     created_by_admin_id/created_at. None of that reaches an anonymous
|     visitor today, so none of it reaches this endpoint either.
|
| Auth: none - this mirrors an already-public page. Rate-limited below as
| the defense-in-depth layer; nothing here can read or write anything a
| browser hitting inquiry.html couldn't already.
*/

require_once "db.php";

header("Content-Type: application/json");

const RATE_LIMIT_WINDOW_SECONDS = 60;
const RATE_LIMIT_MAX_REQUESTS = 30;

function ensureApiRateLimitTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS api_rate_limits (
              id            INT AUTO_INCREMENT PRIMARY KEY,
              bucket_key    VARCHAR(191) NOT NULL,
              window_start  INT          NOT NULL,
              request_count INT          NOT NULL DEFAULT 0,
              UNIQUE KEY uniq_bucket (bucket_key, window_start),
              KEY idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user, or the table already exists under a race -
        // either way, rateLimited() below fails open rather than 500ing
        // a request over a table it couldn't create.
    }
}

/**
 * True if this caller has exceeded the limit for this endpoint in the
 * current window. Fails open (never blocks) on any DB error - a broken
 * limiter should not be able to take the endpoint down with it.
 */
function rateLimited(PDO $pdo, $endpoint)
{
    ensureApiRateLimitTable($pdo);

    $ip = $_SERVER["HTTP_X_FORWARDED_FOR"] ?? $_SERVER["REMOTE_ADDR"] ?? "unknown";
    // A forwarded-for header can carry a chain; the first entry is the
    // original client as the nearest proxy saw it.
    $ip = trim(explode(",", $ip)[0]);

    $window = intdiv(time(), RATE_LIMIT_WINDOW_SECONDS);
    $bucketKey = $endpoint . ":" . $ip;

    try {
        $pdo->prepare("
            INSERT INTO api_rate_limits (bucket_key, window_start, request_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE request_count = request_count + 1
        ")->execute([$bucketKey, $window]);

        $stmt = $pdo->prepare("
            SELECT request_count FROM api_rate_limits
            WHERE bucket_key = ? AND window_start = ?
            LIMIT 1
        ");
        $stmt->execute([$bucketKey, $window]);
        $count = (int) ($stmt->fetchColumn() ?: 0);

        // Cheap opportunistic cleanup - old windows for every bucket, not
        // just this one. Harmless to run on every request; the table is
        // tiny and this keeps it from growing without a cron job.
        $pdo->prepare("DELETE FROM api_rate_limits WHERE window_start < ?")
            ->execute([$window - 5]);

        return $count > RATE_LIMIT_MAX_REQUESTS;

    } catch (PDOException $e) {
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Only GET is supported"]);
    exit;
}

if (rateLimited($pdo, "inquiry-lookup")) {
    http_response_code(429);
    echo json_encode(["success" => false, "message" => "Too many requests - please try again shortly"]);
    exit;
}

$name = trim((string) ($_GET["name"] ?? ""));

// Same shape a slug is generated in (lowercase, digits, single dashes) -
// anything else can't match a real row, so it's rejected before ever
// reaching a query.
if ($name === "" || !preg_match('/^[a-z0-9-]{1,200}$/', $name)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "A valid inquiry name is required"]);
    exit;
}

try {

    $stmt = $pdo->prepare("
        SELECT id, title, intro_text, status
        FROM inquiries
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $inquiry = $stmt->fetch();

    if (!$inquiry) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "No inquiry found with that name"]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, options, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$inquiry["id"]]);

    $fields = array_map(function ($field) {
        $options = trim((string) ($field["options"] ?? ""));

        return [
            "id" => (int) $field["id"],
            "label" => $field["field_label"],
            "type" => $field["field_type"],
            "required" => (bool) $field["required"],
            "options" => $options === "" ? [] : preg_split('/\r\n|\r|\n/', $options),
        ];
    }, $fieldsStmt->fetchAll());

    echo json_encode([
        "success" => true,
        "inquiry" => [
            "title" => $inquiry["title"],
            "intro_text" => $inquiry["intro_text"],
            // 'active' | 'inactive' - lets a caller distinguish "exists but
            // closed" from "not found", same distinction the public form
            // itself already shows a visitor.
            "status" => $inquiry["status"],
        ],
        "fields" => $fields,
    ]);
    exit;

} catch (PDOException $e) {
    // Never echo $e->getMessage() - it can carry schema/column detail.
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Unable to look up that inquiry right now"]);
    exit;
}
