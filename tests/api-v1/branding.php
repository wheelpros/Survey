<?php

/*
| Branding - required by run.php after client-safety.php, sharing its scope.
|
| Each install belongs to one company: its name comes from Settings, and nothing
| sends one install's links or answers to another's address.
*/

[, $b] = pageGet("api/brand.php", "");
check("brand: public, and neutral until it's set", ($b["portal_name"] ?? null) === "Client Portal"
    && ($b["legal_name"] ?? null) === "" && ($b["support_email"] ?? null) === "" && ($b["support_whatsapp_link"] ?? null) === "", json_encode($b));

$brandInput = ["action" => "save_branding", "portal_name" => " Acme Portal ", "legal_name" => "Acme Holdings Ltd",
    "support_email" => "Help@Acme.test", "support_whatsapp" => "+44 7911 123456"];

[, $r] = post("api/settings.php", $sa, $brandInput);
check("brand: only the Director sets it", ($r["success"] ?? true) === false && getSiteSetting($pdo, "portal_name", "") === "", json_encode($r));

[, $r] = post("api/settings.php", $owner, array_merge($brandInput, ["support_email" => "nope"]));
check("brand: a bad email is refused", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/settings.php", $owner, array_merge($brandInput, ["support_whatsapp" => "07911 123456"]));
check("brand: a number with no country code is refused", ($r["success"] ?? true) === false, json_encode($r));

[, $r] = post("api/settings.php", $owner, $brandInput);
check("brand: the Director saves it", ($r["success"] ?? false) === true && ($r["brand"]["portal_name"] ?? null) === "Acme Portal", json_encode($r));

[, $b] = pageGet("api/brand.php", "");
check("brand: every page reads it", ($b["portal_name"] ?? null) === "Acme Portal" && ($b["legal_name"] ?? null) === "Acme Holdings Ltd"
    && ($b["support_email"] ?? null) === "help@acme.test" && ($b["support_whatsapp_link"] ?? null) === "https://wa.me/447911123456", json_encode($b));
check("brand: display values and nothing else", array_keys($b) === ["portal_name", "legal_name", "support_email", "support_whatsapp_link"], json_encode($b));

/** A public file's status and text. */
function siteFile($file)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/api/site-file.php?file=$file", false,
        stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), (string) $body];
}

[$status, $text] = siteFile("robots.txt");
check("site files: robots.txt points at this install", $status === 200 && strpos($text, "Sitemap: http://portal.test/sitemap.xml") !== false
    && strpos($text, "Disallow: /api/") !== false, "HTTP $status $text");
[$status, $text] = siteFile("sitemap.xml");
check("site files: sitemap.xml too", $status === 200 && strpos($text, "<loc>http://portal.test/</loc>") !== false
    && strpos($text, "<?xml") === 0, "HTTP $status $text");
[$status, $text] = siteFile("llms.txt");
check("site files: llms.txt names this portal, and no MCP server it hasn't set", $status === 200 && strpos($text, "# Acme Portal") === 0
    && strpos($text, "## MCP server") === false, "HTTP $status $text");
[$status] = siteFile("../db.php");
check("site files: nothing else is served", $status === 404, "HTTP $status");

/*
| The same code with an install's settings missing, on its own: one PHP process
| per case, since these are read when the file loads.
*/
function mailerProbe($code, array $env)
{
    global $root;
    $script = 'ini_set("log_errors", "1"); ini_set("display_errors", "0");'
        . '$_SERVER["REQUEST_METHOD"] = "GET"; ob_start(); require "api/db.php"; require_once "api/mailer.php"; ob_end_clean(); ' . $code;
    $base = array_diff_key(getenv(), array_flip(["APP_URL", "SMTP_USER", "SMTP_PASS", "SMTP_HOST", "RESPONSE_COPY_EMAIL", "SURVEY_WEBHOOK_URL"]));
    $process = proc_open([PHP_BINARY, "-r", $script], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $root, array_merge($base, $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($process);
    return $out;
}

$send = 'echo "sent=" . sendPortalEmail([["email" => "a@b.test", "name" => "A"]], "S", "<p>x</p>");';

$out = mailerProbe($send, ["SMTP_USER" => "m@x.test", "SMTP_PASS" => "p", "SMTP_HOST" => "127.0.0.1", "SMTP_PORT" => "9"]);
check("mail: no APP_URL, no email", strpos($out, "APP_URL is not set") !== false && strpos($out, "sent=0") !== false, $out);

$out = mailerProbe($send, ["APP_URL" => "http://portal.test", "SMTP_PASS" => "p", "SMTP_HOST" => "127.0.0.1", "SMTP_PORT" => "9"]);
check("mail: no mailbox, no email - and no other company's as a default", strpos($out, "SMTP_USER is not set") !== false && strpos($out, "sent=0") !== false, $out);

$out = mailerProbe('echo APP_URL;', ["APP_URL" => "http://portal.test/"]);
check("mail: links start at the configured address", strpos($out, "http://portal.test") !== false && substr(rtrim($out), -1) !== "/", $out);

$out = mailerProbe('echo adminEmailHtml("H", "I", [], "x.html", "Go");', ["APP_URL" => "http://portal.test"]);
check("mail: signed with this portal's name", strpos($out, "Sent by Acme Portal") !== false && strpos($out, "http://portal.test/x.html") !== false, $out);

// A form's answers leave only for addresses this install set itself.
$source = file_get_contents($root . "/api/survey-submit.php");
check("responses: no built-in address for a copy", strpos($source, "gmail.com") === false && strpos($source, "script.google.com") === false);

$out = mailerProbe('require_once "api/survey-submit.php"; echo "url=" . var_export(getenv("SURVEY_WEBHOOK_URL"), true); sendSurveyToSheet(["name" => "A", "email" => "a@b.test"], ["title" => "T"], []); sendSurveyEmail(["name" => "A", "email" => "a@b.test"], ["title" => "T"], []); echo " done";', []);
check("responses: with neither address set, no copy is attempted", strpos($out, "url=false done") !== false, $out);

foreach (["api/mailer.php", "api/forgot-password.php", "api/sources-lock.php", "api/survey-submit.php", "api/site-file.php", "login.html", "help.html", "settings.html", "dashboard.html"] as $path) {
    check("brand: no other company's name in $path", preg_match('/w ?\| ?zone|w zone|wzone|websitezone/i', file_get_contents($root . "/" . $path)) === 0);
}
