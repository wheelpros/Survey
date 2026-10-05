<?php

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

ensureInquiryTables($pdo);

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

/*
| One response on its own, for inquiry-response.html - the page a phone opens
| instead of expanding an answer inside a list it has no room for.
|
| The inquiry is found from the response rather than asked for, so the link
| only ever has to carry the one id.
*/
$responseId = (int)($_GET["response_id"] ?? 0);

if ($responseId) {

    $stmt = $pdo->prepare("
        SELECT
            inquiry_responses.*,
            inquiries.title, inquiries.slug, inquiries.status
        FROM inquiry_responses
        LEFT JOIN inquiries ON inquiries.id = inquiry_responses.inquiry_id
        WHERE inquiry_responses.id = ?
        LIMIT 1
    ");
    $stmt->execute([$responseId]);
    $response = $stmt->fetch();

    if (!$response || !$response["title"]) {
        echo json_encode(["success" => false, "message" => "Response not found"]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$response["inquiry_id"]]);

    $answersStmt = $pdo->prepare("
        SELECT field_id, answer_text FROM inquiry_response_answers WHERE response_id = ?
    ");
    $answersStmt->execute([$responseId]);

    $answers = [];
    foreach ($answersStmt->fetchAll() as $row) {
        $answers[$row["field_id"]] = $row["answer_text"];
    }

    /* `position` is what the page falls back to when no answer looks like a
       name - "Lead 4" has to mean the same thing here as in the list. */
    $positionStmt = $pdo->prepare("
        SELECT COUNT(*) FROM inquiry_responses
        WHERE inquiry_id = ? AND (submitted_at > ? OR (submitted_at = ? AND id > ?))
    ");
    $positionStmt->execute([
        $response["inquiry_id"], $response["submitted_at"],
        $response["submitted_at"], $responseId
    ]);

    // "Response 4 of 12" needs the 12.
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_id = ?");
    $totalStmt->execute([$response["inquiry_id"]]);
    $total = (int)$totalStmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "id" => (int)$response["inquiry_id"],
        "title" => $response["title"],
        "name" => $response["slug"],
        "total" => $total,
        "status" => $response["status"],
        "fields" => $fieldsStmt->fetchAll(),
        "response" => [
            "id" => (int)$response["id"],
            "submitted_at" => $response["submitted_at"],
            // 'mcp' when an AI assistant sent it; null/'web' for the form.
            // Read with ?? because the column is added lazily.
            "source" => $response["source"] ?? null,
            "position" => (int)$positionStmt->fetchColumn(),
            "answers" => $answers
        ]
    ]);
    exit;
}

$inquiryId = (int)($_GET["inquiry_id"] ?? 0);

if (!$inquiryId) {
    echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
    exit;
}

$inquiryStmt = $pdo->prepare("SELECT id, title, slug, status FROM inquiries WHERE id = ? LIMIT 1");
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

// One row per answered invite - could be several now that every Copy Link
// click generates its own independent link.
// `*` rather than naming `source`: that column is added lazily, and a
// database that couldn't ALTER must still list its responses.
$responsesStmt = $pdo->prepare("
    SELECT *
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

// Only what the page uses - submission_key is the MCP server's business.
$responses = array_map(function ($r) use ($answersByResponse) {
    return [
        "id" => $r["id"],
        "submitted_at" => $r["submitted_at"],
        "source" => $r["source"] ?? null,
        "answers" => $answersByResponse[$r["id"]] ?? [],
    ];
}, $responses);

echo json_encode([
    "success" => true,
    "id" => (int)$inquiry["id"],
    "title" => $inquiry["title"],
    // The responses page mints a link from its own empty state, so it needs
    // the readable half of the URL the same way the list does.
    "name" => $inquiry["slug"],
    "status" => $inquiry["status"],
    "fields" => $fields,
    "responses" => $responses
]);
