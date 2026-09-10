<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

if (!$currentAdmin) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$isOwner = $currentAdmin["role"] === "owner";
$isAccountManager = $currentAdmin["role"] === "account_manager";

if (!$isOwner && !$isAccountManager) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$inquiryId = (int)($_GET["inquiry_id"] ?? 0);

if (!$inquiryId) {
    echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
    exit;
}

$inquiryStmt = $pdo->prepare("
    SELECT
        inquiries.id, inquiries.title, inquiries.intro_text, inquiries.slug,
        inquiries.active, inquiries.created_at, inquiries.assigned_account_manager_id,
        manager.name AS manager_name, manager.email AS manager_email
    FROM inquiries
    LEFT JOIN admins manager ON manager.id = inquiries.assigned_account_manager_id
    WHERE inquiries.id = ?
    LIMIT 1
");
$inquiryStmt->execute([$inquiryId]);
$inquiry = $inquiryStmt->fetch();

if (!$inquiry) {
    echo json_encode(["success" => false, "message" => "Inquiry not found"]);
    exit;
}

// An account_manager only ever sees results for the inquiry they were
// specifically assigned to - never anyone else's.
if ($isAccountManager && (int)$inquiry["assigned_account_manager_id"] !== (int)$currentAdmin["id"]) {
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

$responsesStmt = $pdo->prepare("
    SELECT id, submitted_at
    FROM inquiry_responses
    WHERE inquiry_id = ?
    ORDER BY submitted_at DESC
");
$responsesStmt->execute([$inquiryId]);
$responses = $responsesStmt->fetchAll();

$responseIds = array_map(fn($r) => (int)$r["id"], $responses);
$answersByResponse = [];

if (!empty($responseIds)) {
    $placeholders = implode(",", array_fill(0, count($responseIds), "?"));
    $answersStmt = $pdo->prepare("
        SELECT response_id, field_id, answer_text
        FROM inquiry_response_answers
        WHERE response_id IN ($placeholders)
    ");
    $answersStmt->execute($responseIds);

    foreach ($answersStmt->fetchAll() as $row) {
        $answersByResponse[$row["response_id"]][$row["field_id"]] = $row["answer_text"];
    }
}

$responses = array_map(function ($r) use ($answersByResponse) {
    $r["answers"] = $answersByResponse[$r["id"]] ?? [];
    return $r;
}, $responses);

echo json_encode([
    "success" => true,
    "inquiry" => $inquiry,
    "fields" => $fields,
    "responses" => $responses
]);
