<?php

require_once "db.php";
require_once "survey-writes.php";

header("Content-Type: application/json; charset=UTF-8");

ensureNotificationsTable($pdo);
// The queue below reads users.company_name.
ensureUserProfileColumns($pdo);

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

if (!$currentAdmin) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

// The account manager is the desk a submitted form waits on, with the owner
// alongside for oversight: only they may approve or reject. Every other admin
// may still read this endpoint, but only to watch their own submissions sit
// in the queue.
$canReview = isSurveyReviewer($currentAdmin);

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {

    $singleSurveyId = (int)($_GET["survey_id"] ?? 0);

    if ($singleSurveyId) {

        $stmt = $pdo->prepare("
            SELECT
                surveys.id,
                surveys.title,
                surveys.description,
                surveys.status,
                surveys.created_at,
                surveys.reviewed_at,
                surveys.review_note,
                surveys.assigned_user_id,
                users.name AS user_name,
                users.email AS user_email,
                users.company_name AS company_name,
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

        // A non-reviewer only gets to open what they wrote themselves.
        if (!$canReview && (int) $survey["created_by_admin_id"] !== (int) $currentAdmin["id"]) {
            echo json_encode([
                "success" => false,
                "message" => "Unauthorized"
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

    // The queue is the queue: forms still waiting on a decision, nothing else.
    // The page no longer offers history filters, so neither does this.
    $statusFilter = "pending_review";

    $sql = "
        SELECT
            surveys.id,
            surveys.title,
            surveys.description,
            surveys.status,
            surveys.created_at,
            surveys.reviewed_at,
            surveys.review_note,
            surveys.assigned_user_id,
            users.name AS user_name,
            users.email AS user_email,
            users.company_name AS company_name,
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

    $sql .= " WHERE surveys.status = ?";
    $params = [$statusFilter];

    // An admin who cannot decide sees only their own submissions - the point
    // of the queue for them is "mine are still waiting", not oversight.
    if (!$canReview) {
        $sql .= " AND surveys.created_by_admin_id = ?";
        $params[] = $currentAdmin["id"];
    }

    $sql .= " ORDER BY surveys.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "canReview" => $canReview,
        "surveys" => $stmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input["action"] ?? "";

    /*
    | The decision is reviewSurveyForm() in survey-writes.php, shared with
    | api/v1: who may decide, the note a return needs, deciding only once,
    | and who hears about it are the same whether it's clicked here or
    | confirmed through an AI assistant.
    */
    try {
        reviewSurveyForm($pdo, $currentAdmin, $input["surveyId"] ?? 0, $action, $input["note"] ?? "");
    } catch (PortalWriteError $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => $action === "approve"
            ? "Survey approved and sent to the user"
            : "Survey rejected and sent back to the creator"
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);
