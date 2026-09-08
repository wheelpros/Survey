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
// owner switched on their global inquiries_access flag - this isn't
// per-inquiry anymore, it's all-or-nothing for the whole page.
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

    // Access is global now - owner and any access-granted account_manager
    // both see every inquiry, not a filtered slice.
    $stmt = $pdo->query("
        SELECT id, title, slug, status, expires_at, created_at
        FROM inquiries
        ORDER BY created_at DESC
    ");

    echo json_encode([
        "success" => true,
        "inquiries" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST" || $method === "PUT") {

    // Building/editing an inquiry page is owner-only - an account manager
    // only ever views and follows up on the single answer once it's in.
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
            // 1-hour window passes, whichever comes first.
            $stmt = $pdo->prepare("
                INSERT INTO inquiries (title, intro_text, slug, created_by_admin_id, status, expires_at)
                VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 1 HOUR))
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

            // Once it's been answered, the page is done - editing it after
            // the fact would rewrite the record of what was actually asked.
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

echo json_encode(["success" => false, "message" => "Invalid request"]);
