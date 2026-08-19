<?php

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$adminStmt = $pdo->prepare("
    SELECT id, name, role
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");
$adminStmt->execute([$token]);
$currentAdmin = $adminStmt->fetch();

// Only the account manager reviews surveys. The owner can also open this
// page for oversight, same as they can see everything else.
if (!$currentAdmin || !in_array($currentAdmin["role"], ["account_manager", "owner"], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $singleSurveyId = (int)($_GET["survey_id"] ?? 0);

    if ($singleSurveyId) {

        $stmt = $pdo->prepare("
            SELECT
                surveys.id,
                surveys.title,
                surveys.status,
                surveys.created_at,
                surveys.reviewed_at,
                surveys.review_note,
                surveys.assigned_user_id,
                users.name AS user_name,
                users.email AS user_email,
                surveys.created_by_admin_id,
                creator.name AS created_by_name,
                creator.email AS created_by_email,
                surveys.reviewed_by_admin_id,
                reviewer.name AS reviewed_by_name
            FROM surveys
            JOIN users ON users.id = surveys.assigned_user_id
            LEFT JOIN admins creator ON creator.id = surveys.created_by_admin_id
            LEFT JOIN admins reviewer ON reviewer.id = surveys.reviewed_by_admin_id
            WHERE surveys.id = ?
            LIMIT 1
        ");
        $stmt->execute([$singleSurveyId]);
        $survey = $stmt->fetch();

        if (!$survey) {
            echo json_encode([
                "success" => false,
                "message" => "Survey not found"
            ]);
            exit;
        }

        $qStmt = $pdo->prepare("
            SELECT id, question_text, question_type, required, sort_order, chips, max_file_size_mb
            FROM survey_questions
            WHERE survey_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $qStmt->execute([$singleSurveyId]);

        echo json_encode([
            "success" => true,
            "survey" => $survey,
            "questions" => $qStmt->fetchAll()
        ]);
        exit;
    }

    // Filter by review state. Defaults to the actual review queue; the
    // history tabs on the page pass status=pending / status=rejected /
    // status=all to look back at past decisions.
    $statusFilter = $_GET["status"] ?? "pending_review";

    $allowedStatuses = ["pending_review", "pending", "rejected", "completed", "all"];
    if (!in_array($statusFilter, $allowedStatuses, true)) {
        $statusFilter = "pending_review";
    }

    $sql = "
        SELECT
            surveys.id,
            surveys.title,
            surveys.status,
            surveys.created_at,
            surveys.reviewed_at,
            surveys.review_note,
            surveys.assigned_user_id,
            users.name AS user_name,
            users.email AS user_email,
            surveys.created_by_admin_id,
            creator.name AS created_by_name,
            creator.email AS created_by_email,
            surveys.reviewed_by_admin_id,
            reviewer.name AS reviewed_by_name
        FROM surveys
        JOIN users ON users.id = surveys.assigned_user_id
        LEFT JOIN admins creator ON creator.id = surveys.created_by_admin_id
        LEFT JOIN admins reviewer ON reviewer.id = surveys.reviewed_by_admin_id
    ";

    if ($statusFilter === "all") {
        $sql .= " ORDER BY surveys.created_at DESC";
        $stmt = $pdo->query($sql);
    } else {
        $sql .= " WHERE surveys.status = ? ORDER BY surveys.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$statusFilter]);
    }

    echo json_encode([
        "success" => true,
        "surveys" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $surveyId = (int)($input["surveyId"] ?? 0);
    $action = $input["action"] ?? "";
    $note = trim($input["note"] ?? "");

    if (!$surveyId || !in_array($action, ["approve", "reject"], true)) {
        echo json_encode([
            "success" => false,
            "message" => "A survey and a valid action (approve or reject) are required"
        ]);
        exit;
    }

    if ($action === "reject" && $note === "") {
        echo json_encode([
            "success" => false,
            "message" => "Please add a short note explaining the rejection"
        ]);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT id, status
        FROM surveys
        WHERE id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$surveyId]);
    $survey = $checkStmt->fetch();

    if (!$survey) {
        echo json_encode([
            "success" => false,
            "message" => "Survey not found"
        ]);
        exit;
    }

    if ($survey["status"] !== "pending_review") {
        echo json_encode([
            "success" => false,
            "message" => "This survey has already been reviewed or is no longer pending"
        ]);
        exit;
    }

    if ($action === "approve") {

        // Approving is what actually releases the survey to the assigned
        // user - this is the moment it becomes visible/deliverable to them.
        $stmt = $pdo->prepare("
            UPDATE surveys
            SET status = 'pending',
                reviewed_by_admin_id = ?,
                review_note = NULL,
                reviewed_at = NOW()
            WHERE id = ? AND status = 'pending_review'
        ");
        $stmt->execute([$currentAdmin["id"], $surveyId]);

        if ($stmt->rowCount() === 0) {
            echo json_encode([
                "success" => false,
                "message" => "This survey has already been reviewed by someone else"
            ]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Survey approved and sent to the user"
        ]);
        exit;

    } else {

        $stmt = $pdo->prepare("
            UPDATE surveys
            SET status = 'rejected',
                reviewed_by_admin_id = ?,
                review_note = ?,
                reviewed_at = NOW()
            WHERE id = ? AND status = 'pending_review'
        ");
        $stmt->execute([$currentAdmin["id"], $note, $surveyId]);

        if ($stmt->rowCount() === 0) {
            echo json_encode([
                "success" => false,
                "message" => "This survey has already been reviewed by someone else"
            ]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Survey rejected and sent back to the creator"
        ]);
        exit;
    }
}

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);
