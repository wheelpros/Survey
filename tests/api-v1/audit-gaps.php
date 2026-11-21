<?php

/*
| Audit gaps - required by run.php after rbac-security.php, whose people,
| clients and rbSend() it reuses (Ada 9601 on client 9701, Ben 9602 on 9702,
| Cy 9603 an Account Manager with no clients).
|
| Findings from docs/rbac-audit-2026-11-20.md that the RBAC work had left
| open, re-checked line by line on 2026-11-20:
|   S7   a form upload is shown inline only when its bytes are an image
|   S9   the older calendar actions keep to the admin's clients
|   S10  Reject only removes a sign-up that is still waiting
|   S12  a Files upload can't be handed to someone else's client
*/

// ── S9: the older calendar actions ──────────────────────────────────────

$pdo->exec("INSERT INTO admin_user_assignments (admin_id, user_id) VALUES (9603, 9701)");
forgetPermissions();
[$status, $body] = rbSend("GET", "api/calendar.php?action=get_admin_calendar_data&user_id=9702", "tok-rb-cy");
check("S9: calendar data for a client who isn't theirs looks missing", $status === 404 && !isset($body["appointments"]), "HTTP $status");
[$status, $body] = rbSend("GET", "api/calendar.php?action=get_admin_calendar_data&user_id=9701", "tok-rb-cy");
check("S9: their own client's calendar still reads", $status === 200 && ($body["success"] ?? false) === true, "HTTP $status");

$before = (int) one($pdo, "SELECT COUNT(*) AS n FROM appointments WHERE user_id = 9702")["n"];
[$status] = rbSend("POST", "api/calendar.php?action=create_appointment", "tok-rb-cy",
    ["action" => "create_appointment", "user_id" => 9702, "title" => "Probe", "date" => "2030-01-02", "time" => "10:00"]);
$after = (int) one($pdo, "SELECT COUNT(*) AS n FROM appointments WHERE user_id = 9702")["n"];
check("S9: and no meeting is booked with someone else's client", $status === 404 && $after === $before, "HTTP $status, $before -> $after");
$pdo->exec("DELETE FROM admin_user_assignments WHERE admin_id = 9603 AND user_id = 9701");

// ── S10: Reject is for waiting sign-ups ─────────────────────────────────

seed($pdo, "users", [
    ["id" => 9790, "name" => "Approved Co", "email" => "ok@gap.test", "approved" => 1, "company_name" => "Approved Co"],
    ["id" => 9791, "name" => "Waiting Co", "email" => "wait@gap.test", "approved" => 0, "company_name" => "Waiting Co"],
]);
[$status] = rbSend("POST", "api/admin-users.php", $rbOwner, ["action" => "reject", "userId" => 9790]);
check("S10: Reject refuses an approved client", $status === 409 && one($pdo, "SELECT id FROM users WHERE id = 9790"), "HTTP $status");
[$status, $body] = rbSend("POST", "api/admin-users.php", $rbOwner, ["action" => "reject", "userId" => 9791]);
check("S10: and still removes a waiting sign-up", $status === 200 && ($body["success"] ?? false) === true
    && !one($pdo, "SELECT id FROM users WHERE id = 9791"), "HTTP $status");
$pdo->exec("DELETE FROM users WHERE id IN (9790, 9791)");

// ── S7: inline view only for real images ────────────────────────────────

$gapFiles = [];
$html = sys_get_temp_dir() . "/gap-html-" . getmypid() . ".png";
file_put_contents($html, "<html><script>alert(1)</script></html>");
$gapFiles[] = $html;
$png = sys_get_temp_dir() . "/gap-png-" . getmypid() . ".png";
file_put_contents($png, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg=="));
$gapFiles[] = $png;
seed($pdo, "survey_uploaded_files", [
    ["id" => 9792, "response_id" => 9722, "question_id" => 1, "user_id" => 9702, "original_name" => "x.png", "stored_name" => "x", "file_path" => $html, "file_type" => "image/png"],
    ["id" => 9793, "response_id" => 9722, "question_id" => 1, "user_id" => 9702, "original_name" => "y.png", "stored_name" => "y", "file_path" => $png, "file_type" => "text/html"],
]);

/** Response headers of a GET, lower-cased names. */
function gapHeaders($path)
{
    global $port;
    @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]]));
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (strpos($line, ":") !== false) {
            [$k, $v] = explode(":", $line, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
    }
    return $out;
}

$h = gapHeaders("api/download-survey-file.php?id=9792&mode=view&token=$rbBen");
check("S7: HTML labelled image/png is not shown inline", stripos($h["content-disposition"] ?? "", "attachment") === 0
    && stripos($h["content-type"] ?? "", "html") === false, json_encode($h));
check("S7: and is sent with nosniff and a sandbox", ($h["x-content-type-options"] ?? "") === "nosniff"
    && strpos($h["content-security-policy"] ?? "", "sandbox") !== false, json_encode($h));
$h = gapHeaders("api/download-survey-file.php?id=9793&mode=view&token=$rbBen");
check("S7: a real image previews inline as image/png, whatever it was labelled", stripos($h["content-disposition"] ?? "", "inline") === 0
    && ($h["content-type"] ?? "") === "image/png", json_encode($h));

// ── S12: Files uploads for a client outside scope ───────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS source_folders (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT, title VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS user_source_files (id INT AUTO_INCREMENT PRIMARY KEY, file_id INT, user_id INT)");
require_once $root . "/api/sources-lock.php";
ensureSourcesLockColumns($pdo);
$pdo->exec("UPDATE admins SET sources_unlock_token = 'gap-unlock', sources_unlock_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = 9601");
seed($pdo, "source_folders", [["id" => 9794, "admin_id" => 9601, "title" => "Ada folder"]]);

/** A Files upload of one small text file for $userId. */
function gapSourceUpload($userId)
{
    global $port;
    $b = "----gap" . bin2hex(random_bytes(6));
    $body = "--$b\r\nContent-Disposition: form-data; name=\"folder_id\"\r\n\r\n9794\r\n"
        . "--$b\r\nContent-Disposition: form-data; name=\"user_id\"\r\n\r\n$userId\r\n"
        . "--$b\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"note.php\"\r\nContent-Type: text/plain\r\n\r\n<?php echo 1;\r\n--$b--\r\n";
    @file_get_contents("http://127.0.0.1:$port/api/source-files.php", false, stream_context_create(["http" => [
        "method" => "POST", "ignore_errors" => true, "timeout" => 20, "content" => $body,
        "header" => "Authorization: Bearer tok-rb-ada\r\nX-Sources-Token: gap-unlock\r\nContent-Type: multipart/form-data; boundary=$b"]]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return (int) ($m[1] ?? 0);
}

$status = gapSourceUpload(9702);
$handed = one($pdo, "SELECT 1 AS x FROM user_source_files usf JOIN source_files sf ON sf.id = usf.file_id WHERE usf.user_id = 9702 AND sf.admin_id = 9601");
$stored = one($pdo, "SELECT 1 AS x FROM source_files WHERE folder_id = 9794");
check("S12: a Files upload for another admin's client is refused before anything is saved", $status === 404 && !$handed && !$stored, "HTTP $status");

$status = gapSourceUpload(9701);
$row = one($pdo, "SELECT sf.stored_name, sf.file_path FROM user_source_files usf JOIN source_files sf ON sf.id = usf.file_id WHERE usf.user_id = 9701 AND sf.folder_id = 9794");
check("S12: their own client's upload still goes through", $status === 200 && $row, "HTTP $status");
check("S13: and a .php upload is stored as .bin", $row && substr($row["stored_name"], -9) === "_note.bin", json_encode($row));
if ($row && is_file($row["file_path"])) {
    $gapFiles[] = $row["file_path"];
}

foreach ($gapFiles as $f) {
    @unlink($f);
}
