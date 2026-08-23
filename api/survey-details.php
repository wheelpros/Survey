<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

$surveyId = (int)($_GET["id"] ?? 0);

if (!$token || !$surveyId) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
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
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, title, description, status, created_at
    FROM surveys
    WHERE id = ? AND assigned_user_id = ?
    LIMIT 1
");
$stmt->execute([$surveyId, $user["id"]]);
$survey = $stmt->fetch();

if (!$survey) {
    echo json_encode(["success" => false, "message" => "Survey not found"]);
    exit;
}

$qStmt = $pdo->prepare("
    SELECT id, question_text, question_type, required, sort_order, chips
    FROM survey_questions
    WHERE survey_id = ?
    ORDER BY sort_order ASC, id ASC
");
$qStmt->execute([$surveyId]);
$questions = $qStmt->fetchAll();

$answers = [];
$files = [];

if ($survey["status"] === "completed") {
    $aStmt = $pdo->prepare("
        SELECT sa.question_id, sa.question_label, sa.answer
        FROM survey_answers sa
        INNER JOIN survey_responses sr ON sr.id = sa.response_id
        WHERE sr.survey_id = ? AND sr.user_id = ?
        ORDER BY sa.id ASC
    ");
    $aStmt->execute([$surveyId, $user["id"]]);
    $answers = $aStmt->fetchAll();

    // survey_answers only stores the file name, so the actual upload has to be
    // returned separately for the response view to render it.
    $fStmt = $pdo->prepare("
        SELECT suf.id, suf.question_id, suf.original_name, suf.file_type
        FROM survey_uploaded_files suf
        INNER JOIN survey_responses sr ON sr.id = suf.response_id
        WHERE sr.survey_id = ? AND suf.user_id = ?
        ORDER BY suf.id ASC
    ");
    $fStmt->execute([$surveyId, $user["id"]]);

    foreach ($fStmt->fetchAll() as $f) {
        $files[(int)$f["question_id"]] = $f;
    }
}

echo json_encode([
    "success" => true,
    "survey" => $survey,
    "questions" => $questions,
    "answers" => $answers,
    "files" => (object)$files
]);