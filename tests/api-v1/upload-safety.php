<?php

/*
| Upload safety - required by run.php after client-safety.php. Its own
| client is Uma (9795).
|
| uploads/ is in the web root, so no upload may be stored under a name a web
| server would run or render (audit S1, S13). The Apache rule in
| docker/uploads.conf is the second wall; php -S has no such rule, which is
| why these check the stored names rather than fetching the files.
*/

require_once $root . "/api/upload-helpers.php";

/** A profile save carrying one file, as the client profile page sends it. */
function uploadProfileImage($token, $filename, $declaredType, $bytes)
{
    global $port;
    $boundary = "----prof" . bin2hex(random_bytes(6));
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nCara\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"profile_image\"; filename=\"$filename\"\r\n"
        . "Content-Type: $declaredType\r\n\r\n$bytes\r\n--$boundary--\r\n";
    $reply = @file_get_contents("http://127.0.0.1:$port/api/update-profile.php", false, stream_context_create([
        "http" => ["method" => "POST", "ignore_errors" => true, "timeout" => 20, "content" => $body,
                   "header" => "Authorization: Bearer $token\r\nContent-Type: multipart/form-data; boundary=$boundary"],
    ]));
    return json_decode((string) $reply, true);
}

// ── Profile image (S1) ───────────────────────────────────────────────────

seed($pdo, "users", [["id" => 9795, "name" => "Uma", "email" => "uma@upl.test", "approved" => 1, "session_token" => "tok-upl", "company_name" => "Upload Co"]]);

$r = uploadProfileImage("tok-upl", "shell.php", "image/png", "<?php echo 6*7;");
$after = one($pdo, "SELECT profile_image FROM users WHERE id = 9795")["profile_image"];
check("uploads: a PHP file labelled image/png is refused", ($r["message"] ?? "") === "Only JPG, PNG, WEBP images allowed" && $after === null, json_encode([$r, $after]));
check("uploads: and nothing named .php lands in uploads/", glob($root . "/uploads/profile_9795_*.php") === [], "");

$r = uploadProfileImage("tok-upl", "shell.php", "image/png", "\x89PNG\r\n\x1a\n<?php echo 6*7;");
check("uploads: a PHP file behind a PNG signature is refused", ($r["message"] ?? "") === "Only JPG, PNG, WEBP images allowed", json_encode($r));

$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==");
$r = uploadProfileImage("tok-upl", "avatar.php", "text/plain", $png);
$saved = (string) ($r["user"]["profile_image"] ?? "");
check("uploads: a real PNG is kept, under .png whatever it was called", ($r["success"] ?? false) === true
    && preg_match('#^uploads/profile_9795_\d+\.png$#', $saved) === 1, json_encode($r));
if ($saved !== "" && is_file($root . "/" . $saved)) {
    unlink($root . "/" . $saved);
}

// ── Stored names for source, survey and team-form files (S13) ────────────

$names = [];
foreach (["x.php", "x.php.png", "x.PHTML", ".htaccess", "page.html", "pic.svg", "Report Q3.pdf", "noext"] as $n) {
    $names[$n] = storedUploadName($n);
}
$unsafe = array_filter($names, function ($stored) {
    return preg_match('/\.(php\d?|phtml|phar|pht|html?|svg|htaccess)(\.|$)/i', $stored) === 1
        || substr_count($stored, ".") !== 1;
});
check("uploads: no stored name keeps a runnable or page extension, or a second dot", $unsafe === [], json_encode($names));
check("uploads: an inert extension is kept", substr($names["Report Q3.pdf"], -14) === "_Report_Q3.pdf", $names["Report Q3.pdf"]);
check("uploads: anything else is stored as .bin", substr($names["x.php"], -6) === "_x.bin" && substr($names["noext"], -10) === "_noext.bin", json_encode($names));
