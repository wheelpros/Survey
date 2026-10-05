<?php

require_once "db.php";
require_once "survey-writes.php";

header("Content-Type: application/json; charset=UTF-8");

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
ensureSurveyColumns($pdo);
// The lists below read users.company_name.
ensureUserProfileColumns($pdo);

// Before beginTransaction() below: CREATE TABLE is DDL and commits
// implicitly, which would strand the rollBack() in the catch.
ensureNotificationsTable($pdo);

$adminStmt = $pdo->prepare("
    SELECT id, role
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
/*
| The review gate exists so an account manager (or the owner) checks a form
| before a client ever sees it. When one of them is the author, that check has
| already happened - asking them to approve their own work would be a queue
| with one person on both ends of it. Their forms go straight to the client.
|
| Everyone else still goes through the gate, and an edit by anyone still
| re-opens it further down.
*/
$isReviewer = isSurveyReviewer($currentAdmin);

$method = $_SERVER["REQUEST_METHOD"];

// prepareChips() and isUserInScope() live in survey-writes.php, shared with
// api/v1 so a form an AI drafts is held to the same rules.

// Forms are seen by whoever wrote them, plus the account manager and the owner,
// who see every form - the same rule admin-survey-review.php applies.
function canSeeSurvey($isReviewer, $adminId, $survey) {
    return $isReviewer || (int)$survey["created_by_admin_id"] === (int)$adminId;
}

if ($method === "GET") {

    $singleSurveyId = (int)($_GET["survey_id"] ?? 0);

    if ($singleSurveyId) {
        $stmt = $pdo->prepare("
            SELECT id, assigned_user_id, title, description, status, created_at,
                   created_by_admin_id, reviewed_by_admin_id, review_note, reviewed_at
            FROM surveys
            WHERE id = ?
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

        // Don't let an admin open someone else's form just by guessing/typing
        // its ID.
        if (!canSeeSurvey($isReviewer, $currentAdmin["id"], $survey)) {
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

    // The users dropdown/filter follows the same owner > super_admin > seo_admin
    // scoping as Clients Management: the owner sees everyone, everyone else only
    // sees the users assigned to their own admin_id.
    if ($currentAdmin["role"] === "owner") {

        // Listed by company, which is what the pickers show.
        $usersStmt = $pdo->query("
            SELECT id, name, email, company_name
            FROM users
            WHERE approved = 1
            ORDER BY COALESCE(NULLIF(company_name, ''), name) ASC
        ");

    } else {

        $usersStmt = $pdo->prepare("
            SELECT users.id, users.name, users.email, users.company_name
            FROM users
            INNER JOIN admin_user_assignments aua
                ON aua.user_id = users.id
            WHERE users.approved = 1
            AND aua.admin_id = ?
            ORDER BY COALESCE(NULLIF(users.company_name, ''), users.name) ASC
        ");

        $usersStmt->execute([$currentAdmin["id"]]);
    }

    // The forms list itself: the account manager and the owner see every
    // form, everyone else only the forms they wrote.
    if ($isReviewer) {

    $surveysStmt = $pdo->query("
        SELECT 
            surveys.id,
            surveys.assigned_user_id,
            surveys.title,
            surveys.status,
            surveys.created_at,
            surveys.review_note,
            surveys.reviewed_at,
            users.name AS user_name,
            users.email AS user_email,
            users.company_name AS company_name,
            creator.name AS created_by_name
        FROM surveys
        JOIN users ON users.id = surveys.assigned_user_id
        LEFT JOIN admins creator ON creator.id = surveys.created_by_admin_id
        ORDER BY surveys.created_at DESC
    ");

} else {

    $surveysStmt = $pdo->prepare("
        SELECT 
            surveys.id,
            surveys.assigned_user_id,
            surveys.title,
            surveys.status,
            surveys.created_at,
            surveys.review_note,
            surveys.reviewed_at,
            users.name AS user_name,
            users.email AS user_email,
            users.company_name AS company_name,
            creator.name AS created_by_name
        FROM surveys
        JOIN users ON users.id = surveys.assigned_user_id
        LEFT JOIN admins creator ON creator.id = surveys.created_by_admin_id
        WHERE surveys.created_by_admin_id = ?
        ORDER BY surveys.created_at DESC
    ");

    $surveysStmt->execute([$currentAdmin["id"]]);
}

    echo json_encode([
        "success" => true,
        "users" => $usersStmt->fetchAll(),
        "surveys" => $surveysStmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST" || $method === "PUT") {

    $input = json_decode(file_get_contents("php://input"), true);

    $surveyId = (int)($input["surveyId"] ?? 0);
    $title = trim($input["title"] ?? "");
    $description = trim($input["description"] ?? "");
    $assignedUserId = (int)($input["assignedUserId"] ?? 0);
    $questions = $input["questions"] ?? [];

    /*
    | Creating is createSurveyForm() in survey-writes.php - the same function
    | api/v1 calls when an AI assistant drafts a form, so the review gate and
    | who hears about it can't drift between the two.
    */
    if ($method === "POST") {
        try {
            createSurveyForm($pdo, $currentAdmin, $title, $description, $assignedUserId, is_array($questions) ? $questions : []);
        } catch (PortalWriteError $e) {
            echo json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => $isReviewer
                ? "Form created and sent to the client"
                : "Survey created successfully - awaiting account manager approval before it's sent to the user"
        ]);
        exit;
    }

    if (!$title || !$assignedUserId || !count($questions)) {
        echo json_encode([
            "success" => false,
            "message" => "Survey title, user and questions are required"
        ]);
        exit;
    }

    // A scoped role can only edit surveys for a user actually in their own
    // pool - never one they can't otherwise see or manage.
    if (!isUserInScope($pdo, $currentAdmin["role"], $currentAdmin["id"], $assignedUserId)) {
        echo json_encode([
            "success" => false,
            "message" => "That user is not in your assigned list"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        if (!$surveyId) {
            throw new Exception("Survey ID is required");
        }

        $checkStmt = $pdo->prepare("
            SELECT id, status, created_by_admin_id
            FROM surveys
            WHERE id = ?
            LIMIT 1
        ");
        $checkStmt->execute([$surveyId]);
        $survey = $checkStmt->fetch();

        if (!$survey || !canSeeSurvey($isReviewer, $currentAdmin["id"], $survey)) {
            throw new Exception("Survey not found");
        }

        // Editable any time before the user has actually completed it -
        // covers surveys still awaiting review, already approved but not
        // yet filled out, and ones the account manager sent back.
        if (!in_array($survey["status"], ["pending_review", "pending", "rejected"], true)) {
            throw new Exception("Only surveys that are pending review, pending, or rejected can be edited");
        }

        // Any edit re-opens the review gate: content changed, so it goes
        // back to the account manager before it can reach the user again.
        // Unless the editor is a reviewer, in which case the check has
        // just happened by definition.
        $updateStmt = $pdo->prepare("
            UPDATE surveys
            SET title = ?, description = ?, assigned_user_id = ?, status = ?,
                reviewed_by_admin_id = ?, review_note = NULL, reviewed_at = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $title,
            $description,
            $assignedUserId,
            $isReviewer ? "pending" : "pending_review",
            $isReviewer ? $currentAdmin["id"] : null,
            $isReviewer ? date("Y-m-d H:i:s") : null,
            $surveyId
        ]);

        $deleteStmt = $pdo->prepare("
            DELETE FROM survey_questions
            WHERE survey_id = ?
        ");
        $deleteStmt->execute([$surveyId]);

        writeSurveyQuestions($pdo, $surveyId, $questions);

        $pdo->commit();

    } catch (Exception $e) {

        $pdo->rollBack();

        echo json_encode([
            "success" => false,
            "message" => $e->getMessage() ?: "Failed to save survey"
        ]);
        exit;
    }

    // After the commit, never inside it - see the rules at the top of
    // notify.php. Both branches leave the form awaiting sign-off unless a
    // reviewer edited it, so the reviewers hear about an edit the same way
    // they hear about a new one.
    announceSurveyForm($pdo, $currentAdmin, $surveyId, $title, $assignedUserId, $isReviewer, false);

    echo json_encode([
        "success" => true,
        "message" => $isReviewer
            ? "Form updated and sent to the client"
            : "Survey updated successfully - sent back for account manager approval"
    ]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);