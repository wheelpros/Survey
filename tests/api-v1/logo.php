<?php

/*
| The logo - required by run.php last, sharing its scope.
|
|   1. Uploading is the Director's alone, and goes by what is in the file.
|   2. It is kept in the database, so a new container still has it, and is
|      served with the type it really is (an SVG used to be served as a PNG).
|   3. get_settings reports it, with a version that changes with the logo.
|
| php -S has no Alias, so these call api/site-logo.php, which Apache serves at
| /logo.png (docker/site-files.conf).
*/

require_once $root . "/api/site-logo-store.php";

/** A POST of one file, as the Settings page sends it. */
function uploadLogoFile($token, $name, $bytes)
{
    global $port;
    $boundary = "----logo" . bin2hex(random_bytes(6));
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"logo\"; filename=\"$name\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n$bytes\r\n--$boundary--\r\n";
    $reply = @file_get_contents("http://127.0.0.1:$port/api/upload-logo.php", false, stream_context_create([
        "http" => ["method" => "POST", "ignore_errors" => true, "timeout" => 20, "content" => $body,
                   "header" => "Authorization: Bearer $token\r\nContent-Type: multipart/form-data; boundary=$boundary"],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $reply, true)];
}

/** The logo as a browser fetches it: [status, headers by lowercase name, body]. */
function fetchLogo($etag = null)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/api/site-logo.php", false, stream_context_create([
        "http" => ["ignore_errors" => true, "timeout" => 10, "header" => $etag ? "If-None-Match: $etag" : ""],
    ]));
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (strpos($line, ":") !== false) {
            [$name, $value] = explode(":", $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), $headers, (string) $body];
}

// Real image bytes, with every byte value a binary column has to keep intact.
$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==");
$jpg = base64_decode("/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==");
$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

// ── Nothing yet ──────────────────────────────────────────────────────────

[$status] = fetchLogo();
check("logo: 404 before there is one", $status === 404, "HTTP $status");
[, $s] = get("settings.php", $owner);
check("logo: get_settings says there is none", array_key_exists("logo_url", $s["data"]) && $s["data"]["logo_url"] === null, json_encode($s["data"]["logo_url"] ?? "missing"));

// A file left on disk by an older deploy still counts until an upload replaces it.
$legacy = siteLogoLegacyPath();
if (!is_file($legacy)) {
    file_put_contents($legacy, $png);
    [$status, $headers, $body] = fetchLogo();
    check("logo: an older file on disk still serves", $status === 200 && $headers["content-type"] === "image/png" && $body === $png, "HTTP $status");
    unlink($legacy);
}

// ── Uploading ────────────────────────────────────────────────────────────

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [$status, $r] = uploadLogoFile($token, "l.png", $png);
    check("logo: a $who can't upload", $status === 403 && ($r["success"] ?? true) === false, "HTTP $status " . json_encode($r));
}
[$status] = uploadLogoFile("nobody", "l.png", $png);
check("logo: no session can't upload", $status === 401, "HTTP $status");
[$status] = uploadLogoFile("tok-client", "l.png", $png);
check("logo: a client can't upload", $status === 401, "HTTP $status");

[$status, $r] = uploadLogoFile($owner, "l.png", "<?php echo 'not an image';");
check("logo: a renamed file is refused", $status === 400 && ($r["success"] ?? true) === false, "HTTP $status " . json_encode($r));
[$status] = uploadLogoFile($owner, "l.svg", "<html><script>alert(1)</script></html>");
check("logo: something that isn't an SVG is refused", $status === 400, "HTTP $status");
[$status, $r] = uploadLogoFile($owner, "big.png", $png . str_repeat("x", SITE_LOGO_MAX_BYTES));
check("logo: over 3 MB is refused", $status === 400 && strpos($r["message"] ?? "", "3 MB") !== false, "HTTP $status " . json_encode($r));

[$status] = fetchLogo();
check("logo: a refused upload saved nothing", $status === 404, "HTTP $status");

// ── Serving ──────────────────────────────────────────────────────────────

[$status, $r] = uploadLogoFile($owner, "l.png", $png);
check("logo: the Director uploads", $status === 200 && ($r["success"] ?? false) === true, "HTTP $status " . json_encode($r));
check("logo: nothing is written to disk", !is_file($legacy));

[$status, $headers, $body] = fetchLogo();
check("logo: comes back byte for byte", $status === 200 && $body === $png && $headers["content-type"] === "image/png",
    "HTTP $status " . ($headers["content-type"] ?? "") . " " . strlen($body));
check("logo: revalidated on every load", strpos($headers["cache-control"] ?? "", "no-cache") !== false
    && isset($headers["etag"]) && ($headers["x-content-type-options"] ?? "") === "nosniff", json_encode($headers));

[$status, , $body] = fetchLogo($headers["etag"]);
check("logo: unchanged is an empty 304", $status === 304 && $body === "", "HTTP $status");

[, $s] = get("settings.php", $owner);
$firstUrl = $s["data"]["logo_url"] ?? "";
check("logo: get_settings has the address", preg_match('~^http://portal\.test/logo\.png\?v=[0-9a-f]{12}$~', $firstUrl) === 1, $firstUrl);

$e = eventRow($pdo, "summary = 'Replaced the portal logo'");
check("logo: logged on Tracking", $e && (int) $e["actor_admin_id"] === 1, json_encode($e));

// An SVG is served as one, sandboxed; it used to be served as a PNG and fail.
[$status] = uploadLogoFile($owner, "l.svg", $svg);
[, $headers, $body] = fetchLogo();
check("logo: an SVG is served as an SVG", $status === 200 && $body === $svg && $headers["content-type"] === "image/svg+xml"
    && strpos($headers["content-security-policy"] ?? "", "sandbox") !== false, json_encode($headers));

// The name doesn't decide the type.
[$status] = uploadLogoFile($owner, "really-a-jpeg.png", $jpg);
[, $headers, $body] = fetchLogo();
check("logo: goes by the contents, not the name", $status === 200 && $body === $jpg && $headers["content-type"] === "image/jpeg", json_encode($headers));

[, $s] = get("settings.php", $owner);
check("logo: a new logo gets a new address", ($s["data"]["logo_url"] ?? "") !== $firstUrl && ($s["data"]["logo_url"] ?? null) !== null, json_encode($s["data"]["logo_url"] ?? null));

$saved = one($pdo, "SELECT COUNT(*) AS n FROM site_logo");
check("logo: one row, replaced each time", (int) $saved["n"] === 1, json_encode($saved));

$pdo->exec("DELETE FROM site_logo");
