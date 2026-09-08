<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role, inquiries_access FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

if (!$currentAdmin) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$hasAccess = $currentAdmin["role"] === "owner"
    || ($currentAdmin["role"] === "account_manager" && (int)$currentAdmin["inquiries_access"] === 1);

if (!$hasAccess) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$inquiryId = (int)($_GET["inquiry_id"] ?? 0);

if (!$inquiryId) {
    echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
    exit;
}

$inquiryStmt = $pdo->prepare("SELECT id, title FROM inquiries WHERE id = ? LIMIT 1");
$inquiryStmt->execute([$inquiryId]);
$inquiry = $inquiryStmt->fetch();

if (!$inquiry) {
    echo json_encode(["success" => false, "message" => "Inquiry not found"]);
    exit;
}

$fieldsStmt = $pdo->prepare("
    SELECT id, field_label, field_type, sort_order
    FROM inquiry_fields
    WHERE inquiry_id = ?
    ORDER BY sort_order ASC, id ASC
");
$fieldsStmt->execute([$inquiryId]);
$fields = $fieldsStmt->fetchAll();

// Single-use link - at most one response will ever exist for this inquiry.
$responseStmt = $pdo->prepare("
    SELECT id, submitted_at
    FROM inquiry_responses
    WHERE inquiry_id = ?
    ORDER BY submitted_at DESC
    LIMIT 1
");
$responseStmt->execute([$inquiryId]);
$response = $responseStmt->fetch();

$answers = [];

if ($response) {
    $answersStmt = $pdo->prepare("
        SELECT field_id, answer_text
        FROM inquiry_response_answers
        WHERE response_id = ?
    ");
    $answersStmt->execute([$response["id"]]);

    foreach ($answersStmt->fetchAll() as $row) {
        $answers[$row["field_id"]] = $row["answer_text"];
    }
}

echo json_encode([
    "success" => true,
    "title" => $inquiry["title"],
    "fields" => $fields,
    "submitted_at" => $response["submitted_at"] ?? null,
    "answers" => $answers
]);
