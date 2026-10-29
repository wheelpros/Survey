<?php

require_once "db.php";
require_once "inquiry-templates.php";

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

$isOwner = $currentAdmin["role"] === "owner";
$method = $_SERVER["REQUEST_METHOD"];

// The slug, question and normalising helpers are in inquiry-templates.php,
// shared with api/v1.

if ($method === "GET") {

    // The account manager picker on inquiry-form.html. Owner-only, like the
    // form it serves: an account manager reads inquiries but does not create
    // or edit them.
    if (isset($_GET["managers"])) {

        if (!$isOwner) {
            echo json_encode(["success" => false, "message" => "Only the Director can manage inquiries"]);
            exit;
        }

        $stmt = $pdo->query("
            SELECT id, name, email
            FROM admins
            WHERE role = 'account_manager'
            ORDER BY name ASC
        ");

        echo json_encode([
            "success" => true,
            "managers" => $stmt->fetchAll()
        ]);
        exit;
    }

    $singleId = (int)($_GET["id"] ?? 0);

    if ($singleId) {

        $stmt = $pdo->prepare("
            SELECT
                inquiries.id, inquiries.title, inquiries.intro_text, inquiries.slug,
                inquiries.status, inquiries.account_manager_admin_id, inquiries.reference,
                inquiries.created_at,
                manager.name AS account_manager_name,
                manager.email AS account_manager_email
            FROM inquiries
            LEFT JOIN admins manager ON manager.id = inquiries.account_manager_admin_id
            WHERE inquiries.id = ?
            LIMIT 1
        ");
        $stmt->execute([$singleId]);
        $inquiry = $stmt->fetch();

        if (!$inquiry) {
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        $inquiry["slug"] = backfillSlug($pdo, $inquiry["id"], $inquiry["title"], $inquiry["slug"]);

        $fieldsStmt = $pdo->prepare("
            SELECT id, field_label, field_type, required, options, sort_order
            FROM inquiry_fields
            WHERE inquiry_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $fieldsStmt->execute([$singleId]);

        echo json_encode([
            "success" => true,
            "inquiry" => $inquiry,
            "fields" => $fieldsStmt->fetchAll(),
            "hasAnswers" => inquiryHasAnswers($pdo, $singleId)
        ]);
        exit;
    }

    // Each inquiry is a template; "leads" counts the invites that were actually
    // answered, "invites" counts every link ever generated for it.
    $stmt = $pdo->query("
        SELECT
            inquiries.id, inquiries.title, inquiries.slug, inquiries.status,
            inquiries.account_manager_admin_id, inquiries.reference, inquiries.created_at,
            manager.name AS account_manager_name,
            (SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_responses.inquiry_id = inquiries.id) AS lead_count
        FROM inquiries
        LEFT JOIN admins manager ON manager.id = inquiries.account_manager_admin_id
        ORDER BY inquiries.created_at DESC, inquiries.id DESC
    ");
    $inquiries = $stmt->fetchAll();

    foreach ($inquiries as &$row) {
        $row["slug"] = backfillSlug($pdo, $row["id"], $row["title"], $row["slug"]);
    }
    unset($row);

    /* The three stat cards. Counted in SQL over everything, deliberately
       ignoring the page the table happens to be showing:

         total   inquiries created
         active  inquiries whose links are open for answering
         people  submissions, across all of them
    */
    $stats = [
        "total" => (int)$pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(),
        "active" => (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE status <> 'inactive'")->fetchColumn(),
        "people" => (int)$pdo->query("SELECT COUNT(*) FROM inquiry_responses")->fetchColumn(),
    ];

    echo json_encode([
        "success" => true,
        "inquiries" => $inquiries,
        "stats" => $stats
    ]);
    exit;
}

if ($method === "POST") {

    // Creating, editing and deleting are all the owner's - an access-granted
    // account manager can only view inquiries and what came back from them.
    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the Director can create inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];
    $status = normaliseStatus($input["status"] ?? "active");
    $managerId = resolveManagerId($pdo, $input["accountManagerId"] ?? 0);
    $reference = normaliseReference($input["reference"] ?? "");

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A title and at least one field are required"]);
        exit;
    }

    try {
        $created = createInquiry($pdo, $currentAdmin, $title, $introText, $fields, $status, $managerId, $reference);
    } catch (PortalWriteError $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Inquiry created successfully",
        "id" => $created["id"],
        "name" => $created["slug"]
    ]);
    exit;
}

if ($method === "PUT") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the Director can edit inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $inquiryId = (int)($input["id"] ?? 0);
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];
    $status = normaliseStatus($input["status"] ?? "active");
    $managerId = resolveManagerId($pdo, $input["accountManagerId"] ?? 0);
    $reference = normaliseReference($input["reference"] ?? "");

    // updateInquiry() in inquiry-templates.php, shared with api/v1. Once an
    // inquiry has answers its questions are locked and only status, account
    // manager and reference are saved.
    try {
        $saved = updateInquiry($pdo, $inquiryId, $title, $introText, is_array($fields) ? $fields : [], $status, $managerId, $reference);
    } catch (PortalWriteError $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }

    if ($saved["locked"]) {
        echo json_encode([
            "success" => true,
            "locked" => true,
            "name" => $saved["slug"],
            "message" => "Status, account manager and reference saved. The questions are locked - this inquiry already has answers."
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Inquiry updated successfully",
        "name" => $saved["slug"]
    ]);
    exit;
}

if ($method === "DELETE") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the Director can delete inquiries"]);
        exit;
    }

    $inquiryId = (int)($_GET["id"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT title FROM inquiries WHERE id = ?");
    $stmt->execute([$inquiryId]);
    $eventTitle = (string) $stmt->fetchColumn();

    // No foreign keys on these tables, so the children go by hand, deepest
    // first - the same thing api/projects.php does for its tasks and members.
    $pdo->beginTransaction();

    try {
        $pdo->prepare("
            DELETE FROM inquiry_response_answers
            WHERE response_id IN (SELECT id FROM inquiry_responses WHERE inquiry_id = ?)
        ")->execute([$inquiryId]);

        $pdo->prepare("DELETE FROM inquiry_responses WHERE inquiry_id = ?")->execute([$inquiryId]);
        $pdo->prepare("DELETE FROM inquiry_invites WHERE inquiry_id = ?")->execute([$inquiryId]);
        $pdo->prepare("DELETE FROM inquiry_fields WHERE inquiry_id = ?")->execute([$inquiryId]);

        $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id = ?");
        $stmt->execute([$inquiryId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        logEvent($pdo, EVENT_INQUIRY_DELETED, "inquiry", $inquiryId, $eventTitle,
            "Deleted inquiry \"" . $eventTitle . "\" and its answers");

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Inquiry deleted"]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to delete inquiry"]);
        exit;
    }
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
