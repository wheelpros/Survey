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

$isOwner = $currentAdmin["role"] === "owner";
$method = $_SERVER["REQUEST_METHOD"];

function generateSlug($pdo) {
    do {
        $slug = bin2hex(random_bytes(8));
        $check = $pdo->prepare("SELECT id FROM inquiry_invites WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);
    } while ($check->fetch());

    return $slug;
}

// Lazily flips any invite whose 24-hour window passed without an answer
// over to 'expired'. No cron here, so this runs on every load instead.
function expireOverdueInvites($pdo) {
    $pdo->prepare("
        UPDATE inquiry_invites
        SET status = 'expired'
        WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()
    ")->execute();
}

function createInvite($pdo, $inquiryId) {
    $slug = generateSlug($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO inquiry_invites (inquiry_id, slug, status, expires_at)
        VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ");
    $stmt->execute([$inquiryId, $slug]);

    return $slug;
}

function inquiryHasAnsweredInvite($pdo, $inquiryId) {
    $stmt = $pdo->prepare("SELECT 1 FROM inquiry_invites WHERE inquiry_id = ? AND status = 'answered' LIMIT 1");
    $stmt->execute([$inquiryId]);
    return (bool)$stmt->fetch();
}

expireOverdueInvites($pdo);

if ($method === "GET") {

    $singleId = (int)($_GET["id"] ?? 0);

    if ($singleId) {

        $stmt = $pdo->prepare("SELECT id, title, intro_text, created_at FROM inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$singleId]);
        $inquiry = $stmt->fetch();

        if (!$inquiry) {
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        $fieldsStmt = $pdo->prepare("
            SELECT id, field_label, field_type, required, sort_order
            FROM inquiry_fields
            WHERE inquiry_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $fieldsStmt->execute([$singleId]);

        echo json_encode([
            "success" => true,
            "inquiry" => $inquiry,
            "fields" => $fieldsStmt->fetchAll(),
            "hasAnsweredInvite" => inquiryHasAnsweredInvite($pdo, $singleId)
        ]);
        exit;
    }

    // Each inquiry is now a template; "Leads" counts invites that were
    // actually answered, "Invites" counts every link ever generated for it.
    $stmt = $pdo->query("
        SELECT
            inquiries.id, inquiries.title, inquiries.created_at,
            (SELECT COUNT(*) FROM inquiry_invites WHERE inquiry_invites.inquiry_id = inquiries.id AND inquiry_invites.status = 'answered') AS lead_count,
            (SELECT COUNT(*) FROM inquiry_invites WHERE inquiry_invites.inquiry_id = inquiries.id) AS invite_count
        FROM inquiries
        ORDER BY inquiries.created_at DESC
    ");

    echo json_encode([
        "success" => true,
        "inquiries" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    // Generating a fresh link for an existing inquiry - open to anyone
    // with page access (owner or a granted account_manager), since this
    // is outreach, not editing the inquiry itself.
    if (($input["action"] ?? "") === "new_invite") {

        $inquiryId = (int)($input["inquiryId"] ?? 0);

        if (!$inquiryId) {
            echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
            exit;
        }

        $checkStmt = $pdo->prepare("SELECT id FROM inquiries WHERE id = ? LIMIT 1");
        $checkStmt->execute([$inquiryId]);

        if (!$checkStmt->fetch()) {
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        $slug = createInvite($pdo, $inquiryId);

        echo json_encode(["success" => true, "slug" => $slug]);
        exit;
    }

    // Creating a brand-new inquiry is owner-only.
    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can create inquiries"]);
        exit;
    }

    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A headline and at least one field are required"]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("
            INSERT INTO inquiries (title, intro_text, created_by_admin_id)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$title, $introText, $currentAdmin["id"]]);
        $inquiryId = $pdo->lastInsertId();

        $fieldStmt = $pdo->prepare("
            INSERT INTO inquiry_fields (inquiry_id, field_label, field_type, required, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($fields as $index => $field) {
            $label = trim($field["label"] ?? "");
            if (!$label) continue;

            $type = ($field["type"] ?? "input") === "textarea" ? "textarea" : "input";
            $required = !empty($field["required"]) ? 1 : 0;

            $fieldStmt->execute([$inquiryId, $label, $type, $required, $index + 1]);
        }

        // First link, generated automatically so the builder can show it
        // right away - every Copy Link click after this creates another.
        $slug = createInvite($pdo, $inquiryId);

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Inquiry created successfully",
            "id" => $inquiryId,
            "slug" => $slug
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => $e->getMessage() ?: "Failed to save inquiry"]);
        exit;
    }
}

if ($method === "PUT") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can edit inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $inquiryId = (int)($input["id"] ?? 0);
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A headline and at least one field are required"]);
        exit;
    }

    // Once any link for this inquiry has actually been answered, changing
    // the fields would delete the field rows historical answers point to
    // (fields cascade-delete their answers). Locking edits here protects
    // that lead data.
    if (inquiryHasAnsweredInvite($pdo, $inquiryId)) {
        echo json_encode(["success" => false, "message" => "This inquiry already has an answered lead and can't be edited"]);
        exit;
    }

    $checkStmt = $pdo->prepare("SELECT id FROM inquiries WHERE id = ? LIMIT 1");
    $checkStmt->execute([$inquiryId]);

    if (!$checkStmt->fetch()) {
        echo json_encode(["success" => false, "message" => "Inquiry not found"]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        $updateStmt = $pdo->prepare("UPDATE inquiries SET title = ?, intro_text = ? WHERE id = ?");
        $updateStmt->execute([$title, $introText, $inquiryId]);

        $deleteFieldsStmt = $pdo->prepare("DELETE FROM inquiry_fields WHERE inquiry_id = ?");
        $deleteFieldsStmt->execute([$inquiryId]);

        $fieldStmt = $pdo->prepare("
            INSERT INTO inquiry_fields (inquiry_id, field_label, field_type, required, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($fields as $index => $field) {
            $label = trim($field["label"] ?? "");
            if (!$label) continue;

            $type = ($field["type"] ?? "input") === "textarea" ? "textarea" : "input";
            $required = !empty($field["required"]) ? 1 : 0;

            $fieldStmt->execute([$inquiryId, $label, $type, $required, $index + 1]);
        }

        $pdo->commit();

        echo json_encode(["success" => true, "message" => "Inquiry updated successfully"]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to save inquiry"]);
        exit;
    }
}

if ($method === "DELETE") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can delete inquiries"]);
        exit;
    }

    $inquiryId = (int)($_GET["id"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    // Cascades to inquiry_fields, inquiry_invites, and (via invites)
    // inquiry_responses + inquiry_response_answers.
    $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id = ?");
    $stmt->execute([$inquiryId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(["success" => false, "message" => "Inquiry not found"]);
        exit;
    }

    echo json_encode(["success" => true, "message" => "Inquiry deleted"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
