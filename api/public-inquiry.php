<?php

require_once "db.php";

header("Content-Type: application/json");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $slug = trim($_GET["slug"] ?? "");

    if (!$slug) {
        echo json_encode(["success" => false, "message" => "Missing link"]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, title, intro_text, status, expires_at
        FROM inquiries
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$slug]);
    $inquiry = $stmt->fetch();

    if (!$inquiry) {
        echo json_encode(["success" => false, "message" => "This link is not valid"]);
        exit;
    }

    if ($inquiry["status"] === "answered") {
        echo json_encode(["success" => false, "message" => "This link has already been used"]);
        exit;
    }

    if ($inquiry["expires_at"] && strtotime($inquiry["expires_at"]) < time()) {
        echo json_encode(["success" => false, "message" => "This link has expired"]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$inquiry["id"]]);

    echo json_encode([
        "success" => true,
        "inquiry" => [
            "title" => $inquiry["title"],
            "intro_text" => $inquiry["intro_text"]
        ],
        "fields" => $fieldsStmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $slug = trim($input["slug"] ?? "");
    $answers = $input["answers"] ?? [];

    if (!$slug) {
        echo json_encode(["success" => false, "message" => "Missing link"]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, status, expires_at FROM inquiries WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $inquiry = $stmt->fetch();

    if (!$inquiry) {
        echo json_encode(["success" => false, "message" => "This link is not valid"]);
        exit;
    }

    if ($inquiry["status"] === "answered") {
        echo json_encode(["success" => false, "message" => "This link has already been used"]);
        exit;
    }

    if ($inquiry["expires_at"] && strtotime($inquiry["expires_at"]) < time()) {
        echo json_encode(["success" => false, "message" => "This link has expired"]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("SELECT id, field_label, required FROM inquiry_fields WHERE inquiry_id = ?");
    $fieldsStmt->execute([$inquiry["id"]]);
    $fields = $fieldsStmt->fetchAll();

    $answersByFieldId = [];
    foreach ($answers as $answer) {
        $answersByFieldId[(int)($answer["fieldId"] ?? 0)] = trim($answer["value"] ?? "");
    }

    foreach ($fields as $field) {
        $value = $answersByFieldId[(int)$field["id"]] ?? "";
        if ((int)$field["required"] === 1 && $value === "") {
            echo json_encode([
                "success" => false,
                "message" => "Please fill in \"" . $field["field_label"] . "\""
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    try {

        // Atomic single-use guard: only flips to 'answered' if it's still
        // 'pending' right now. If two submissions race, only one wins here.
        $claimStmt = $pdo->prepare("
            UPDATE inquiries
            SET status = 'answered'
            WHERE id = ? AND status = 'pending'
        ");
        $claimStmt->execute([$inquiry["id"]]);

        if ($claimStmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "This link has already been used"]);
            exit;
        }

        $responseStmt = $pdo->prepare("INSERT INTO inquiry_responses (inquiry_id) VALUES (?)");
        $responseStmt->execute([$inquiry["id"]]);
        $responseId = $pdo->lastInsertId();

        $answerStmt = $pdo->prepare("
            INSERT INTO inquiry_response_answers (response_id, field_id, answer_text)
            VALUES (?, ?, ?)
        ");

        foreach ($fields as $field) {
            $value = $answersByFieldId[(int)$field["id"]] ?? "";
            $answerStmt->execute([$responseId, $field["id"], $value]);
        }

        $pdo->commit();

        echo json_encode(["success" => true, "message" => "Thanks - your message has been sent."]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to submit. Please try again."]);
        exit;
    }
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
