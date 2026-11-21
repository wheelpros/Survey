<?php

/*
|--------------------------------------------------------------------------
| One submitted response, question by question
|--------------------------------------------------------------------------
|
| Backs response-details.html, which a "New response" notification now opens
| directly (?id=<survey_responses.id>).
|
| Answers are client data, so this asks who is reading. It did not before:
| anyone could walk ?id=1, 2, 3... and read every client's answers, along
| with the ids of the files they uploaded.
|
| Same rule as api/admin-responses.php, the list this page is opened from
| (scope.php's "response"): an admin session is required, forms.responses
| opens any response, and every other admin those from clients assigned to
| them or to forms they built (the
| notification goes to the form's creator, who may not be on the client's
| team). A response outside that is reported as not found rather than
| forbidden, so ids can't be probed for existence.
*/

require_once "db.php";
require_once "scope.php";

header("Content-Type: application/json");

$currentAdmin = requireAdmin($pdo);
requirePage($pdo, "forms"); // "Open X" in Settings > Permissions, as for the list

$id = (int) ($_GET["id"] ?? 0);

if (!$id) {
    denyRequest(400, "A response id is required");
}

// Is this response one this admin may read? (scope.php's "response")
requireRecord($pdo, $currentAdmin, "response", $id, "Response not found");

$stmt = $pdo->prepare("
    SELECT
        sa.question_id,
        sa.answer,
        sa.question_label AS question_text,
        -- The question as it is on the form, so a checklist can be shown with
        -- every option and the ticked ones marked, as the review popup draws it.
        sq.question_type,
        sq.chips,
        suf.id AS file_id,
        suf.original_name AS file_name,
        suf.file_size
    FROM survey_answers sa
    LEFT JOIN survey_questions sq
        ON sq.id = sa.question_id
    LEFT JOIN survey_uploaded_files suf
        ON suf.response_id = sa.response_id
        AND suf.question_id = sa.question_id
    WHERE sa.response_id = ?
    ORDER BY sa.id ASC
");

$stmt->execute([$id]);

echo json_encode([
    "success" => true,
    "answers" => $stmt->fetchAll()
]);
