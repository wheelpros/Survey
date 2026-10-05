<?php

/*
|--------------------------------------------------------------------------
| Forms (surveys): creating one, and the review decision
|--------------------------------------------------------------------------
|
| Shared by the browser endpoints - admin-surveys.php (create) and
| admin-survey-review.php (approve / return) - and by the AI-facing api/v1,
| so a form an assistant drafts goes through exactly the gate, the
| notifications and the emails a form built on the page does. Change the
| behaviour here and both change together.
|
| $actor is the admin acting: at least ["id", "role"], plus "name" where a
| notification names them. Refusals throw PortalWriteError (db.php); the
| caller turns that into its own reply.
|
| Callers must have run ensureSurveyColumns() and ensureNotificationsTable()
| before calling - both are DDL, which can't happen inside the transactions
| below (see notify.php).
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/mailer.php";

const SURVEY_QUESTION_TYPES = ["input", "textarea", "file", "checkbox"];

/*
| The reviewers: they sign forms off, and a form one of them writes is
| released straight to the client - asking them to approve their own work
| would be a queue with one person on both ends of it.
*/
function isSurveyReviewer(array $actor)
{
    return in_array($actor["role"] ?? "", ["account_manager", "owner"], true);
}

/* The form builder sends a question's options as "a, b, c"; api/v1 as a list. */
function prepareChips($chips)
{
    if (is_array($chips)) {
        return json_encode(array_values(array_filter(array_map(function ($c) {
            return trim((string) $c);
        }, $chips), "strlen")));
    }

    $chips = trim($chips ?? "");
    if (!$chips) return json_encode([]);

    $chipsArray = array_filter(
        array_map("trim", explode(",", $chips))
    );

    return json_encode(array_values($chipsArray));
}

// The owner can touch any user; everyone else can only touch a user that's
// actually assigned to their own admin_id - same boundary as the users list.
function isUserInScope($pdo, $role, $adminId, $userId)
{
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

/**
 * A form's questions, in order: [{text, type, chips, maxFileSizeMb}]. Blank
 * questions are skipped; an unknown type becomes a one-line answer. Every
 * question is required, as the builder has always saved them.
 */
function writeSurveyQuestions(PDO $pdo, $surveyId, array $questions)
{
    $qStmt = $pdo->prepare("
        INSERT INTO survey_questions
        (survey_id, question_text, question_type, required, sort_order, chips, max_file_size_mb)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach (array_values($questions) as $index => $question) {

        $questionText = trim($question["text"] ?? "");
        $questionType = $question["type"] ?? "input";
        $chips = $question["chips"] ?? "";

        if (!$questionText) {
            continue;
        }

        if (!in_array($questionType, SURVEY_QUESTION_TYPES, true)) {
            $questionType = "input";
        }

        $maxFileSizeMb = isset($question["maxFileSizeMb"]) && $question["maxFileSizeMb"] !== ""
            ? (int)$question["maxFileSizeMb"]
            : null;

        $qStmt->execute([
            $surveyId,
            $questionText,
            $questionType,
            1,
            $index + 1,
            prepareChips($chips),
            $maxFileSizeMb
        ]);
    }
}

/**
 * Who hears about a saved form. Released already (a reviewer wrote it):
 * the client, who now has it to fill in. Otherwise the reviewers, in the
 * portal and by email, since nobody else can move it on. After commit only.
 */
function announceSurveyForm(PDO $pdo, array $actor, $surveyId, $title, $userId, $released, $isNew)
{
    if ($released) {
        notify(
            $pdo,
            "user",
            (int) $userId,
            NOTIFY_FORM_APPROVED,
            "A new form is ready for you",
            $title . " is waiting to be filled in.",
            "survey.html?id=" . (int) $surveyId,
            "admin",
            (int) $actor["id"]
        );
        return;
    }

    notifyReviewers(
        $pdo,
        NOTIFY_FORM_AWAITING_REVIEW,
        $isNew ? "New form awaiting approval" : "Updated form awaiting approval",
        $title . " needs a review before it reaches the user.",
        // Opens this form's review, not just the dashboard.
        "admin.html?review=" . (int) $surveyId,
        (int) $actor["id"]
    );

    // The same reviewers, by email: a form waiting on sign-off is invisible
    // to the client until one of them opens the portal.
    emailReviewersAboutForm($pdo, $title, (int) $userId, (int) $actor["id"], $isNew, (int) $surveyId);
}

/**
 * Creates a form for a client. 'pending_review' waits on a reviewer and is
 * invisible to the client; 'pending' is released and waits on the client.
 * A reviewer's own form is released at once, stamped as reviewed by them -
 * unless $forceReview, which api/v1 sets: a form an AI drafted always waits
 * for a person to approve it, whoever asked for it.
 *
 * Returns ["id" => int, "status" => "pending_review" | "pending"].
 */
function createSurveyForm(PDO $pdo, array $actor, $title, $description, $userId, array $questions, $forceReview = false)
{
    $title = trim((string) $title);
    $description = trim((string) $description);
    $userId = (int) $userId;

    if (!$title || !$userId || !count($questions)) {
        throw new PortalWriteError("Survey title, user and questions are required");
    }

    // A scoped role can only create surveys for a user actually in their own
    // pool - never one they can't otherwise see or manage.
    if (!isUserInScope($pdo, $actor["role"], $actor["id"], $userId)) {
        throw new PortalWriteError("That user is not in your assigned list", 403);
    }

    $released = isSurveyReviewer($actor) && !$forceReview;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO surveys
                (title, description, assigned_user_id, status, created_by_admin_id,
                 reviewed_by_admin_id, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $description,
            $userId,
            $released ? "pending" : "pending_review",
            $actor["id"],
            $released ? $actor["id"] : null,
            $released ? date("Y-m-d H:i:s") : null
        ]);

        $surveyId = (int) $pdo->lastInsertId();

        writeSurveyQuestions($pdo, $surveyId, $questions);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new PortalWriteError("Failed to save survey", 500);
    }

    // After the commit, never inside it - see the rules at the top of notify.php.
    announceSurveyForm($pdo, $actor, $surveyId, $title, $userId, $released, true);

    return ["id" => $surveyId, "status" => $released ? "pending" : "pending_review"];
}

/**
 * The review gate: 'approve' releases a form to its client, 'reject' sends
 * it back to its author with a note. Only reviewers decide, and only once -
 * a form someone else already decided is refused.
 *
 * Returns the survey row as it was before the decision.
 */
function reviewSurveyForm(PDO $pdo, array $actor, $surveyId, $action, $note)
{
    $surveyId = (int) $surveyId;
    $note = trim((string) $note);

    if (!isSurveyReviewer($actor)) {
        throw new PortalWriteError("Only an account manager or the owner can approve or reject a form", 403);
    }

    if (!$surveyId || !in_array($action, ["approve", "reject"], true)) {
        throw new PortalWriteError("A survey and a valid action (approve or reject) are required");
    }

    if ($action === "reject" && $note === "") {
        throw new PortalWriteError("Please add a short note explaining the rejection");
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
        throw new PortalWriteError("Survey not found", 404);
    }

    if ($survey["status"] !== "pending_review") {
        throw new PortalWriteError("This survey has already been reviewed or is no longer pending", 409);
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
        $stmt->execute([$actor["id"], $surveyId]);

        if ($stmt->rowCount() === 0) {
            throw new PortalWriteError("This survey has already been reviewed by someone else", 409);
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
            "survey.html?id=" . (int) $surveyId,
            "admin",
            (int) $actor["id"]
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
            (int) $actor["id"]
        );

        return $survey;
    }

    $stmt = $pdo->prepare("
        UPDATE surveys
        SET status = 'rejected',
            reviewed_by_admin_id = ?,
            review_note = ?,
            reviewed_at = NOW()
        WHERE id = ? AND status = 'pending_review'
    ");
    $stmt->execute([$actor["id"], $note, $surveyId]);

    if ($stmt->rowCount() === 0) {
        throw new PortalWriteError("This survey has already been reviewed by someone else", 409);
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
        (int) $actor["id"]
    );

    return $survey;
}
