<?php

/*
|--------------------------------------------------------------------------
| Shared rate limiting for the public and AI-facing endpoints
|--------------------------------------------------------------------------
|
| Pulled out of api/inquiry-lookup.php once a second and third endpoint
| needed it (api/inquiry-submit.php, and the submit half of
| api/public-inquiry.php). Requires db.php to have run first ($pdo).
|
|   if (rateLimited($pdo, "inquiry-lookup", 30, 300)) { ...429... }
|
| Two kinds of caller:
|   - a visitor, counted per address - clientIp() below, which only
|     believes X-Forwarded-For as far back as proxies we trust;
|   - the MCP server (Wzone-ai/), recognised by its shared X-MCP-Key and
|     counted as ONE bucket for every AI caller together. It already limits
|     each of its own callers per address before anything reaches PHP.
|
| Every function here fails open on a database error: a broken limiter
| must not be able to take an endpoint down with it.
*/

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
 * True if this caller has made more than its share of requests to
 * $endpoint in the current window: $perIpMax for a visitor, $mcpMax for
 * the MCP server's shared bucket.
 *
 * `window_start` holds the window's actual start time (a Unix timestamp),
 * not a window number, so endpoints with different window lengths can share
 * the table and one cleanup rule. Rows written before this held window
 * numbers; they are far in the past by that measure and the first cleanup
 * removes them.
 */
function rateLimited(PDO $pdo, $endpoint, $perIpMax, $mcpMax, $windowSeconds = 60)
{
    ensureApiRateLimitTable($pdo);

    if (isMcpServer()) {
        $bucketKey = $endpoint . ":mcp";
        $max = $mcpMax;
    } else {
        $bucketKey = $endpoint . ":" . clientIp();
        $max = $perIpMax;
    }

    $now = time();
    $window = $now - ($now % $windowSeconds);

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

        // Cheap opportunistic cleanup - every bucket, not just this one.
        // An hour comfortably outlives any window in use; the table stays
        // tiny without a cron job.
        $pdo->prepare("DELETE FROM api_rate_limits WHERE window_start < ?")
            ->execute([$now - 3600]);

        return $count > $max;

    } catch (PDOException $e) {
        return false;
    }
}
