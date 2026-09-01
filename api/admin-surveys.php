<?php

require_once "db.php";
require_once "notify.php";

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
ensureSurveyColumns($pdo);

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
$isReviewer = in_array($currentAdmin["role"], ["account_manager", "owner"], true);

$method = $_SERVER["REQUEST_METHOD"];

function prepareChips($chips) {
    $chips = trim($chips ?? "");
    if (!$chips) return json_encode([]);

    $chipsArray = array_filter(
        array_map("trim", explode(",", $chips))
    );

    return json_encode(array_values($chipsArray));
}

// The owner can touch any user; everyone else can only touch a user that's
// actually assigned to their own admin_id - same boundary as the users list.
function isUserInScope($pdo, $role, $adminId, $userId) {
    if ($role === "owner") {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM admin_user_assignments
        WHERE admin_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId, $userId]);

    return (bool)$stmt->fetch();
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

        // Don't let a scoped role open a survey that belongs to a user
        // outside their own pool just by guessing/typing its ID.
        if (!isUserInScope($pdo, $currentAdmin["role"], $currentAdmin["id"], (int)$survey["assigned_user_id"])) {
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

        $usersStmt = $pdo->query("
            SELECT id, name, email
            FROM users
            WHERE approved = 1
            ORDER BY name ASC
        ");

    } else {

        $usersStmt = $pdo->prepare("
            SELECT users.id, users.name, users.email
            FROM users
            INNER JOIN admin_user_assignments aua
                ON aua.user_id = users.id
            WHERE users.approved = 1
            AND aua.admin_id = ?
            ORDER BY users.name ASC
        ");

        $usersStmt->execute([$currentAdmin["id"]]);
    }

    // Same scoping for the surveys list itself: a survey is visible if its
    // assigned user is visible.
    if ($currentAdmin["role"] === "owner") {

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
            creator.name AS created_by_name
        FROM surveys
        JOIN users ON users.id = surveys.assigned_user_id
        INNER JOIN admin_user_assignments aua
            ON aua.user_id = users.id
        LEFT JOIN admins creator ON creator.id = surveys.created_by_admin_id
        WHERE aua.admin_id = ?
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

    if (!$title || !$assignedUserId || !count($questions)) {
        echo json_encode([
            "success" => false,
            "message" => "Survey title, user and questions are required"
        ]);
        exit;
    }

    // A scoped role can only create/edit surveys for a user actually in
    // their own pool - never one they can't otherwise see or manage.
    if (!isUserInScope($pdo, $currentAdmin["role"], $currentAdmin["id"], $assignedUserId)) {
        echo json_encode([
            "success" => false,
            "message" => "That user is not in your assigned list"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        if ($method === "POST") {

            /*
            | 'pending_review' means waiting on a reviewer and invisible to the
            | client; 'pending' means released and waiting on the client. See
            | admin-survey-review.php, which is what moves one to the other for
            | everybody else. A reviewer writing their own form lands on
            | 'pending' directly, stamped as reviewed by themselves so the
            | record still says who released it.
            */
            $stmt = $pdo->prepare("
                INSERT INTO surveys
                    (title, description, assigned_user_id, status, created_by_admin_id,
                     reviewed_by_admin_id, reviewed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $title,
                $description,
                $assignedUserId,
                $isReviewer ? "pending" : "pending_review",
                $currentAdmin["id"],
                $isReviewer ? $currentAdmin["id"] : null,
                $isReviewer ? date("Y-m-d H:i:s") : null
            ]);

            $surveyId = $pdo->lastInsertId();

        } else {

            if (!$surveyId) {
                throw new Exception("Survey ID is required");
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
        }

        $qStmt = $pdo->prepare("
            INSERT INTO survey_questions
            (survey_id, question_text, question_type, required, sort_order, chips, max_file_size_mb)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($questions as $index => $question) {

            $questionText = trim($question["text"] ?? "");
            $questionType = $question["type"] ?? "input";
            $chips = $question["chips"] ?? "";

            if (!$questionText) {
                continue;
            }

            // 🔽 قمنا بإضافة "checkbox" هنا لكي يسمح الـ PHP بحفظه في قاعدة البيانات 🔽
            if (!in_array($questionType, ["input", "textarea", "file", "checkbox"])) {
                $questionType = "input";
            }
            
            $maxFileSizeMb = isset($question["maxFileSizeMb"]) && $question["maxFileSizeMb"] !== ""
            ? (int)$question["maxFileSizeMb"]
            : null;

            $qStmt->execute([
                $surveyId,
                $questionText,
                $questionType, // هنا سيتم حفظ كلمة checkbox بنجاح في الداتابيز
                1,
                $index + 1,
                prepareChips($chips),
                $maxFileSizeMb
            ]);
        }

        $pdo->commit();

        // After the commit, never inside it - see the rules at the top of
        // notify.php.
        if ($isReviewer) {

            // Released already, so the client is the one who needs telling -
            // there is no reviewer left waiting to hear about it.
            notify(
                $pdo,
                "user",
                $assignedUserId,
                NOTIFY_FORM_APPROVED,
                "A new form is ready for you",
                $title . " is waiting to be filled in.",
                "dashboard.html",
                "admin",
                (int) $currentAdmin["id"]
            );

        } else {

            // Both branches leave the form awaiting sign-off, so the reviewers
            // hear about an edit the same way they hear about a new one.
            notifyReviewers(
                $pdo,
                NOTIFY_FORM_AWAITING_REVIEW,
                $method === "POST"
                    ? "New form awaiting approval"
                    : "Updated form awaiting approval",
                $title . " needs a review before it reaches the user.",
                "admin.html",
                (int) $currentAdmin["id"]
            );
        }

        if ($isReviewer) {
            $message = $method === "POST"
                ? "Form created and sent to the client"
                : "Form updated and sent to the client";
        } else {
            $message = $method === "POST"
                ? "Survey created successfully - awaiting account manager approval before it's sent to the user"
                : "Survey updated successfully - sent back for account manager approval";
        }

        echo json_encode([
            "success" => true,
            "message" => $message
        ]);
        exit;

    } catch (Exception $e) {

        $pdo->rollBack();

        echo json_encode([
            "success" => false,
            "message" => $e->getMessage() ?: "Failed to save survey"
        ]);
        exit;
    }
}

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);