<?php

/*
|--------------------------------------------------------------------------
| logo.png, from the database
|--------------------------------------------------------------------------
|
| docker/site-files.conf maps /logo.png here, so every page's <img> and the MCP
| server's icon keep the address they always had. Public: the login page shows
| it before anyone has signed in.
|
| "no-cache" lets a browser keep the image but ask each time, so a new upload
| shows on the next page load; an unchanged logo comes back as an empty 304.
|
| An SVG can carry scripts. They don't run from an <img>, but they would if
| someone opened this address directly, so the reply is sandboxed.
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/site-logo-store.php";

$info = siteLogoInfo($pdo);

if (!$info) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    header("Cache-Control: no-cache");
    echo "No logo has been uploaded.";
    exit;
}

$etag = '"' . $info["version"] . '"';

header("Cache-Control: public, no-cache");
header("ETag: " . $etag);
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

if (trim((string) ($_SERVER["HTTP_IF_NONE_MATCH"] ?? "")) === $etag) {
    http_response_code(304);
    header_remove("Content-Type");
    exit;
}

$bytes = siteLogoBytes($pdo);

header("Content-Type: " . $info["mime"]);
header("Content-Length: " . strlen((string) $bytes));
echo $bytes;
