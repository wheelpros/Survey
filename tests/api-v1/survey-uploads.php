<?php

/*
| Survey uploads - required by run.php after upload-safety.php. Its own
| client is Sam (9830).
|
| U-04: the name a client uploads a file under is shown on survey.html, so
| it is stored without path parts, control characters or quotes (the page
| escapes it too; the canary covers that side).
| F-09: a submit that fails after a file was moved into place rolls back the
| response, so it must not leave that file behind on disk either.
*/

/** A survey submit from the page: answers plus files keyed by question id. */
function surveyUploadSubmit($token, $surveyId, array $answers, array $files)
{
    global $port;
    $b = "----sv" . bin2hex(random_bytes(6));
    $body = "--$b\r\nContent-Disposition: form-data; name=\"surveyId\"\r\n\r\n$surveyId\r\n"
        . "--$b\r\nContent-Disposition: form-data; name=\"answers\"\r\n\r\n" . json_encode($answers) . "\r\n";
    foreach ($files as $questionId => [$filename, $bytes]) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"files[$questionId]\"; filename=\"$filename\"\r\n"
            . "Content-Type: image/png\r\n\r\n$bytes\r\n";
    }
    $body .= "--$b--\r\n";
    $reply = @file_get_contents("http://127.0.0.1:$port/api/submit-survey.php", false, stream_context_create(["http" => [
        "method" => "POST", "ignore_errors" => true, "timeout" => 20, "content" => $body,
        "header" => "Authorization: Bearer $token\r\nContent-Type: multipart/form-data; boundary=$b"]]));
    return json_decode((string) $reply, true);
}

/** Every file stored for the account, under any response folder. */
function surveyUploadFilesOnDisk($accountId)
{
    global $root;
    return array_values(array_filter(glob($root . "/uploads/survey-files/$accountId/*/*") ?: [], "is_file"));
}

seed($pdo, "users", [["id" => 9830, "name" => "Sam", "email" => "sam@svu.test", "approved" => 1, "session_token" => "tok-svu", "company_name" => "Survey Co"]]);
seed($pdo, "surveys", [
    ["id" => 9831, "title" => "Brand files", "assigned_user_id" => 9830, "status" => "pending"],
    ["id" => 9832, "title" => "Logo", "assigned_user_id" => 9830, "status" => "pending"],
]);
seed($pdo, "survey_questions", [
    ["id" => 98310, "survey_id" => 9831, "question_text" => "Logo", "question_type" => "file", "sort_order" => 1],
    ["id" => 98311, "survey_id" => 9831, "question_text" => "Brand guide", "question_type" => "file", "sort_order" => 2],
    ["id" => 98320, "survey_id" => 9832, "question_text" => "Logo", "question_type" => "file", "sort_order" => 1],
]);

$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==");

// ── F-09: a failed submit leaves no file ────────────────────────────────

// The first file is moved into place, then the second question has none.
$r = surveyUploadSubmit("tok-svu", 9831, [
    ["questionId" => 98310, "questionLabel" => "Logo", "answer" => ""],
    ["questionId" => 98311, "questionLabel" => "Brand guide", "answer" => ""],
], [98310 => ["logo.png", $png]]);
check("survey uploads: a submit missing a required file is refused", ($r["success"] ?? true) === false
    && strpos((string) ($r["message"] ?? ""), "File is required") !== false, json_encode($r));
check("survey uploads: and saves no response", one($pdo, "SELECT COUNT(*) AS n FROM survey_responses WHERE survey_id = 9831")["n"] == 0
    && one($pdo, "SELECT status FROM surveys WHERE id = 9831")["status"] === "pending");
$left = surveyUploadFilesOnDisk(9830);
check("survey uploads: and leaves no file on disk (F-09)", $left === [], json_encode($left));

// ── U-04: the stored display name ───────────────────────────────────────

// A quote can't cross the multipart header and PHP drops path parts itself,
// so the request carries a control character and markup; the cleaner is
// checked on quotes and paths directly below.
$hostile = "logo\x01<img src=x onerror=alert(1)>.png";
$r = surveyUploadSubmit("tok-svu", 9832, [["questionId" => 98320, "questionLabel" => "Logo", "answer" => ""]], [98320 => [$hostile, $png]]);
check("survey uploads: a file upload goes through", ($r["success"] ?? false) === true, json_encode($r));
$row = one($pdo, "SELECT f.original_name, f.stored_name, a.answer FROM survey_uploaded_files f
    JOIN survey_responses s ON s.id = f.response_id
    JOIN survey_answers a ON a.response_id = s.id AND a.question_id = f.question_id
    WHERE s.survey_id = 9832");
$name = (string) ($row["original_name"] ?? "");
check("survey uploads: the shown name has no quote, control character or path (U-04)", $row
    && strpbrk($name, "\"\\/\x01") === false && $name !== "", json_encode($row));
check("survey uploads: the markup stays as text for the page to escape", strpos($name, "<img") !== false, json_encode($row));
require_once $root . "/api/upload-helpers.php";
$clean = cleanAttachmentName("../../etc\\x\"y\x07.png");
check("survey uploads: the cleaner drops quotes, control characters and paths", $clean === "xy.png", $clean);
check("survey uploads: and the answer records that same name", $row && $row["answer"] === $name, json_encode($row));
check("survey uploads: and it is stored under an inert name", $row && preg_match('/^\d+_[a-f0-9]{10}_[A-Za-z0-9_-]+\.png$/', (string) $row["stored_name"]) === 1, json_encode($row));

foreach (surveyUploadFilesOnDisk(9830) as $f) {
    @unlink($f);
}
foreach (glob($root . "/uploads/survey-files/9830/*", GLOB_ONLYDIR) ?: [] as $d) {
    @rmdir($d);
}
@rmdir($root . "/uploads/survey-files/9830");
