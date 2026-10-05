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
// The MCP server (Wzone-ai/) reaches this endpoint from one address on
// behalf of every AI client, and already limits each of those per IP
// itself - so it gets one shared bucket of its own, sized for all of
// them together, instead of the 30 one visitor gets.
const RATE_LIMIT_MCP_MAX_REQUESTS = 300;

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
 * True if $ip falls inside $cidr ("203.0.113.0/24", "2001:db8::/32", or a
 * bare address). Works for IPv4 and IPv6; a v4 address never matches a v6
 * range.
 */
function ipInCidr($ip, $cidr)
{
    $parts = explode("/", trim($cidr), 2);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($parts[0]);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ipBin) * 8;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }

    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
}

/**
 * A hop whose X-Forwarded-For entry can be believed: anything on a
 * private or reserved range (Coolify's Traefik, the Docker network), plus
 * whatever the TRUSTED_PROXIES env var lists - comma-separated addresses
 * or CIDRs, e.g. a CDN's published ranges if one is ever put in front.
 */
function isTrustedProxy($ip)
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    foreach (explode(",", (string) getenv("TRUSTED_PROXIES")) as $cidr) {
        if (trim($cidr) !== "" && ipInCidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * The caller's address, as the nearest proxy we trust saw it.
 *
 * X-Forwarded-For is whatever the client sent with each proxy's view of
 * its peer appended on the right, so only the right-hand end can be
 * believed. Walk it right to left through trusted proxies; the first hop
 * that isn't one is the client. Taking the left-most entry instead - as
 * this used to - lets anyone pick their own rate-limit bucket by sending a
 * made-up header.
 */
function clientIp()
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
    if (!isTrustedProxy($ip)) {
        // Reached us directly - any forwarded header is the client's own.
        return $ip;
    }

    $hops = array_reverse(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? "")));
    foreach ($hops as $hop) {
        $hop = trim($hop);
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
            break;
        }
        $ip = $hop;
        if (!isTrustedProxy($hop)) {
            break;
        }
    }
    return $ip;
}

/**
 * True if the request carries the MCP server's shared key (MCP_UPSTREAM_KEY,
 * set to the same value on both deployments). Unset on either side means
 * the MCP server is treated like any other visitor.
 */
function isMcpServer()
{
    $key = (string) getenv("MCP_UPSTREAM_KEY");
    $sent = (string) ($_SERVER["HTTP_X_MCP_KEY"] ?? "");
    return $key !== "" && $sent !== "" && hash_equals($key, $sent);
}

/**
 * True if this caller has exceeded the limit for this endpoint in the
 * current window. Fails open (never blocks) on any DB error - a broken
 * limiter should not be able to take the endpoint down with it.
 */
function rateLimited(PDO $pdo, $endpoint)
{
    ensureApiRateLimitTable($pdo);

    if (isMcpServer()) {
        $bucketKey = $endpoint . ":mcp";
        $max = RATE_LIMIT_MCP_MAX_REQUESTS;
    } else {
        $bucketKey = $endpoint . ":" . clientIp();
        $max = RATE_LIMIT_MAX_REQUESTS;
    }

    $window = intdiv(time(), RATE_LIMIT_WINDOW_SECONDS);

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

        return $count > $max;

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
