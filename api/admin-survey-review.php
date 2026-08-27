<?php

require_once "db.php";
require_once "notify.php";

header("Content-Type: application/json");

ensureNotificationsTable($pdo);

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
$canReview = in_array($currentAdmin["role"], ["account_manager", "owner"], true);

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

    if (!$canReview) {
        echo json_encode([
            "success" => false,
            "message" => "Only an account manager or the owner can approve or reject a form"
        ]);
        exit;
    }

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

    // title, assigned_user_id and created_by_admin_id ride along for the
    // notifications below, rather than costing a second read.
    $checkStmt = $pdo->prepare("
        SELECT id, status, title, assigned_user_id, created_by_admin_id
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

        // Approving is the moment the form reaches the user, so both ends
        // hear about it: the person who has to fill it in, and the admin who
        // wrote it and has been waiting on the gate.
        notify(
            $pdo,
            "user",
            (int) $survey["assigned_user_id"],
            NOTIFY_FORM_APPROVED,
            "A new form is ready for you",
            $survey["title"] . " is waiting to be filled in.",
            "dashboard.html",
            "admin",
            (int) $currentAdmin["id"]
        );

        notify(
            $pdo,
            "admin",
            (int) $survey["created_by_admin_id"],
            NOTIFY_FORM_APPROVED,
            "Your form was approved",
            $survey["title"] . " has been sent to the assigned user.",
            "admin.html",
            "admin",
            (int) $currentAdmin["id"]
        );

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

        // Only the creator: the assigned user never knew this form existed,
        // because a rejected form has not been released to them.
        notify(
            $pdo,
            "admin",
            (int) $survey["created_by_admin_id"],
            NOTIFY_FORM_REJECTED,
            "Your form was sent back",
            $survey["title"] . ": " . $note,
            "admin-form-builder.html?edit=" . (int) $survey["id"],
            "admin",
            (int) $currentAdmin["id"]
        );

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
