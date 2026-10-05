<?php

require_once "db.php";
require_once "survey-submit.php";

header("Content-Type: application/json; charset=UTF-8");

// Before the transaction in submitSurveyResponse(): CREATE TABLE is DDL and
// commits implicitly, which would strand its rollBack().
ensureNotificationsTable($pdo);

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "No token provided"
    ]);
    exit;
}

$surveyId = (int)($_POST["surveyId"] ?? 0);
$answers = json_decode($_POST["answers"] ?? "[]", true);
$answers = is_array($answers) ? $answers : [];

if (!$surveyId || !count($answers)) {
    echo json_encode([
        "success" => false,
        "message" => "Survey ID and answers are required"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email, approved
    FROM users
    WHERE session_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || (int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

/*
| Everything from here - the released-form check, storing the answers and the
| uploads, marking it completed, the notifications, the CSV email and the
| Apps Script copy - is submitSurveyResponse() in survey-submit.php, shared
| with api/v1 so a form a client fills in through their AI assistant is
| handled identically.
*/
try {
    submitSurveyResponse($pdo, $user, $surveyId, $answers, $_FILES["files"] ?? null);
} catch (PortalWriteError $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Survey submitted successfully"
]);
