<?php

/*
|--------------------------------------------------------------------------
| One submitted response, question by question
|--------------------------------------------------------------------------
|
| Backs response-details.html, which a "New response" notification now opens
| directly (?id=<survey_responses.id>).
|
| Answers are client data, so this asks who is reading. It did not before:
| anyone could walk ?id=1, 2, 3... and read every client's answers, along
| with the ids of the files they uploaded.
|
| Same rule as api/admin-responses.php, the list this page is opened from:
| an admin session is required, the owner may open any response, and every
| other admin only those from clients assigned to them. A response outside
| that is reported as not found rather than forbidden, so ids can't be probed
| for existence.
*/

require_once "db.php";

header("Content-Type: application/json");

function refuse($status, $message)
{
    http_response_code($status);
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    refuse(401, "Unauthorized");
}

$adminStmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$adminStmt->execute([$token]);
$currentAdmin = $adminStmt->fetch();

if (!$currentAdmin) {
    refuse(401, "Unauthorized");
}

$id = (int) ($_GET["id"] ?? 0);

if (!$id) {
    refuse(400, "A response id is required");
}

// Is this response one this admin may read?
$sql = "SELECT sr.id FROM survey_responses sr";
$params = [];

if ($currentAdmin["role"] !== "owner") {
    $sql .= "
        INNER JOIN admin_user_assignments aua
            ON aua.user_id = sr.user_id
           AND aua.admin_id = ?
    ";
    $params[] = $currentAdmin["id"];
}

$sql .= " WHERE sr.id = ? LIMIT 1";
$params[] = $id;

$visible = $pdo->prepare($sql);
$visible->execute($params);

if (!$visible->fetchColumn()) {
    refuse(404, "Response not found");
}

$stmt = $pdo->prepare("
    SELECT
        sa.question_id,
        sa.answer,
        sa.question_label AS question_text,
        suf.id AS file_id,
        suf.original_name AS file_name,
        suf.file_size
    FROM survey_answers sa
    LEFT JOIN survey_uploaded_files suf
        ON suf.response_id = sa.response_id
        AND suf.question_id = sa.question_id
    WHERE sa.response_id = ?
    ORDER BY sa.id ASC
");

$stmt->execute([$id]);

echo json_encode([
    "success" => true,
    "answers" => $stmt->fetchAll()
]);
