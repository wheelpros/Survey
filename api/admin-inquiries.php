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

// The owner always has access. An account_manager only gets in if the
// owner switched on their global inquiries_access flag.
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
        $check = $pdo->prepare("SELECT id FROM inquiries WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);
    } while ($check->fetch());

    return $slug;
}

// Lazily flips any inquiry whose 24-hour window passed without an answer
// over to 'expired'. There's no background cron in this app, so this runs
// on every load instead - the status is only ever stale between admin
// page views, which self-corrects the moment anyone opens the list again.
function expireOverdueInquiries($pdo) {
    $pdo->prepare("
        UPDATE inquiries
        SET status = 'expired'
        WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()
    ")->execute();
}

expireOverdueInquiries($pdo);

if ($method === "GET") {

    $singleId = (int)($_GET["id"] ?? 0);

    if ($singleId) {

        $stmt = $pdo->prepare("
            SELECT id, title, intro_text, slug, status, expires_at, created_at
            FROM inquiries
            WHERE id = ?
            LIMIT 1
        ");
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
            "fields" => $fieldsStmt->fetchAll()
        ]);
        exit;
    }

    // Access is global - owner and any access-granted account_manager both
    // see every inquiry. Leads is 0 or 1 (single-use link), shown as a count
    // for a quick at-a-glance read of the list.
    $stmt = $pdo->query("
        SELECT
            inquiries.id, inquiries.title, inquiries.slug, inquiries.status,
            inquiries.expires_at, inquiries.created_at,
            (SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_responses.inquiry_id = inquiries.id) AS lead_count
        FROM inquiries
        ORDER BY inquiries.created_at DESC
    ");

    echo json_encode([
        "success" => true,
        "inquiries" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST" || $method === "PUT") {

    // Building/editing an inquiry page is owner-only.
    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can create or edit inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $inquiryId = (int)($input["id"] ?? 0);
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A headline and at least one field are required"]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        if ($method === "POST") {

            $slug = generateSlug($pdo);

            // Single-use link: pending until answered once, or until the
            // 24-hour window passes, whichever comes first.
            $stmt = $pdo->prepare("
                INSERT INTO inquiries (title, intro_text, slug, created_by_admin_id, status, expires_at)
                VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([$title, $introText, $slug, $currentAdmin["id"]]);

            $inquiryId = $pdo->lastInsertId();

        } else {

            if (!$inquiryId) {
                throw new Exception("Inquiry id is required");
            }

            $checkStmt = $pdo->prepare("SELECT id, slug, status FROM inquiries WHERE id = ? LIMIT 1");
            $checkStmt->execute([$inquiryId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                throw new Exception("Inquiry not found");
            }

            if ($existing["status"] === "answered") {
                throw new Exception("This inquiry has already been answered and can't be edited");
            }

            $slug = $existing["slug"];

            $updateStmt = $pdo->prepare("
                UPDATE inquiries
                SET title = ?, intro_text = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$title, $introText, $inquiryId]);

            $deleteFieldsStmt = $pdo->prepare("DELETE FROM inquiry_fields WHERE inquiry_id = ?");
            $deleteFieldsStmt->execute([$inquiryId]);
        }

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

        echo json_encode([
            "success" => true,
            "message" => $method === "POST" ? "Inquiry created successfully" : "Inquiry updated successfully",
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

if ($method === "DELETE") {

    // Deleting an inquiry is owner-only. Cascades to its fields, any
    // response, and that response's answers via the existing foreign keys.
    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can delete inquiries"]);
        exit;
    }

    $inquiryId = (int)($_GET["id"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

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
