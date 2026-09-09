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

// The owner sees/manages everything. An account_manager only ever sees
// the specific inquiries the owner assigned them to, and only to view
// results - never to create, edit, or delete.
if (!$isOwner && !$isAccountManager) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

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
            SELECT
                inquiries.id, inquiries.title, inquiries.intro_text, inquiries.slug,
                inquiries.active, inquiries.created_at,
                inquiries.assigned_account_manager_id,
                manager.name AS manager_name, manager.email AS manager_email
            FROM inquiries
            LEFT JOIN admins manager ON manager.id = inquiries.assigned_account_manager_id
            WHERE inquiries.id = ?
            LIMIT 1
        ");
        $stmt->execute([$singleId]);
        $inquiry = $stmt->fetch();

        if (!$inquiry) {
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        if ($isAccountManager && (int)$inquiry["assigned_account_manager_id"] !== (int)$currentAdmin["id"]) {
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

    if ($isOwner) {

        $stmt = $pdo->query("
            SELECT
                inquiries.id, inquiries.title, inquiries.slug, inquiries.active, inquiries.created_at,
                inquiries.assigned_account_manager_id,
                manager.name AS manager_name,
                (SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_responses.inquiry_id = inquiries.id) AS lead_count
            FROM inquiries
            LEFT JOIN admins manager ON manager.id = inquiries.assigned_account_manager_id
            ORDER BY inquiries.created_at DESC
        ");

        $managersStmt = $pdo->query("
            SELECT id, name, email FROM admins WHERE role = 'account_manager' ORDER BY name ASC
        ");

        echo json_encode([
            "success" => true,
            "inquiries" => $stmt->fetchAll(),
            "accountManagers" => $managersStmt->fetchAll()
        ]);
        exit;
    }

    // account_manager - only the inquiries assigned to them.
    $stmt = $pdo->prepare("
        SELECT
            inquiries.id, inquiries.title, inquiries.slug, inquiries.active, inquiries.created_at,
            inquiries.assigned_account_manager_id,
            (SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_responses.inquiry_id = inquiries.id) AS lead_count
        FROM inquiries
        WHERE assigned_account_manager_id = ?
        ORDER BY inquiries.created_at DESC
    ");
    $stmt->execute([$currentAdmin["id"]]);

    echo json_encode([
        "success" => true,
        "inquiries" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST" || $method === "PUT") {

    // Creating and editing are owner-only - an account_manager can only view.
    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can create or edit inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $inquiryId = (int)($input["id"] ?? 0);
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $managerId = (int)($input["assignedAccountManagerId"] ?? 0);
    $active = isset($input["active"]) ? (int)(bool)$input["active"] : 1;
    $fields = $input["fields"] ?? [];

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A title and at least one field are required"]);
        exit;
    }

    if ($managerId) {
        $managerStmt = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND role = 'account_manager' LIMIT 1");
        $managerStmt->execute([$managerId]);
        if (!$managerStmt->fetch()) {
            echo json_encode(["success" => false, "message" => "That account manager was not found"]);
            exit;
        }
    }

    $pdo->beginTransaction();

    try {

        if ($method === "POST") {

            $slug = generateSlug($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO inquiries (title, intro_text, slug, created_by_admin_id, assigned_account_manager_id, active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $introText, $slug, $currentAdmin["id"], $managerId ?: null, $active]);

            $inquiryId = $pdo->lastInsertId();

        } else {

            if (!$inquiryId) {
                throw new Exception("Inquiry id is required");
            }

            $checkStmt = $pdo->prepare("SELECT id, slug FROM inquiries WHERE id = ? LIMIT 1");
            $checkStmt->execute([$inquiryId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                throw new Exception("Inquiry not found");
            }

            $slug = $existing["slug"];

            $updateStmt = $pdo->prepare("
                UPDATE inquiries
                SET title = ?, intro_text = ?, assigned_account_manager_id = ?, active = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$title, $introText, $managerId ?: null, $active, $inquiryId]);

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

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can delete inquiries"]);
        exit;
    }

    $inquiryId = (int)($_GET["id"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    // Cascades to inquiry_fields, inquiry_responses, and their answers.
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
