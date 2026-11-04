<?php

/*
|--------------------------------------------------------------------------
| A client submitting a form
|--------------------------------------------------------------------------
|
| Shared by submit-survey.php (survey.html) and the AI-facing api/v1, so a
| form a client fills in through their assistant is stored and announced
| exactly like one sent from the page: the response and its answers, the
| form marked completed, the client's admins notified, the CSV email, and
| the Google Apps Script copy.
|
| $user is the person submitting, with their account_id (withClientAccount()):
| the form and its answers belong to the account, and submitted_by says who. $answers is the page's shape,
| [{questionId, questionLabel, answer}]. $files is the page's $_FILES["files"]
| (uploads keyed by question id), or null - api/v1 never uploads, so a form
| with a file question has to be finished on the page.
|
| Refusals throw PortalWriteError (db.php). Callers run
| ensureNotificationsTable() first (DDL can't happen inside the transaction).
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";

/**
 * The client's own form, if it has been released to them: 'pending' is
 * waiting on the client, 'completed' already answered. Anything else - still
 * with a reviewer, or returned to its author - was never theirs to see, so it
 * reads as not found, the same as survey-details.php.
 */
function loadClientSurvey(PDO $pdo, $userId, $surveyId)
{
    $stmt = $pdo->prepare("
        SELECT id, title, description, status, created_at
        FROM surveys
        WHERE id = ? AND assigned_user_id = ?
          AND status IN ('pending', 'completed')
        LIMIT 1
    ");
    $stmt->execute([(int) $surveyId, (int) $userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Stores a submission and tells everyone who hears about one. Returns the
 * new survey_responses id.
 */
function submitSurveyResponse(PDO $pdo, array $user, $surveyId, array $answers, $files = null)
{
    $surveyId = (int) $surveyId;

    if (!$surveyId || !count($answers)) {
        throw new PortalWriteError("Survey ID and answers are required");
    }

    $survey = loadClientSurvey($pdo, $user["account_id"], $surveyId);

    if (!$survey) {
        throw new PortalWriteError("Survey not found", 404);
    }

    if ($survey["status"] === "completed") {
        throw new PortalWriteError("This survey has already been submitted", 409);
    }

    $qStmt = $pdo->prepare("
        SELECT id, question_text, question_type, max_file_size_mb
        FROM survey_questions
        WHERE survey_id = ?
    ");
    $qStmt->execute([$surveyId]);

    $questionsMap = [];

    foreach ($qStmt->fetchAll() as $q) {
        $questionsMap[(int)$q["id"]] = $q;
    }

    $uploadRoot = __DIR__ . "/../uploads/survey-files";

    if (!is_dir($uploadRoot)) {
        mkdir($uploadRoot, 0777, true);
    }

    $pdo->beginTransaction();

    try {

        // One submission per form, however many people are on the account: of two
        // at the same moment the second waits here, then finds it taken. First, so
        // the one that loses has saved nothing.
        $claim = $pdo->prepare("UPDATE surveys SET status = 'completed' WHERE id = ? AND status = 'pending'");
        $claim->execute([$survey["id"]]);

        if ($claim->rowCount() === 0) {
            throw new PortalWriteError("This survey has already been submitted", 409);
        }

        $responseStmt = $pdo->prepare("
            INSERT INTO survey_responses
            (user_id, survey_id, survey_title, status, submitted_by_id, submitted_by_name)
            VALUES (?, ?, ?, 'completed', ?, ?)
        ");

        $responseStmt->execute([
            $user["account_id"],
            $survey["id"],
            $survey["title"],
            $user["id"],
            $user["name"]
        ]);

        $responseId = (int) $pdo->lastInsertId();

        $answerStmt = $pdo->prepare("
            INSERT INTO survey_answers
            (response_id, question_id, question_label, answer)
            VALUES (?, ?, ?, ?)
        ");

        $fileStmt = $pdo->prepare("
            INSERT INTO survey_uploaded_files
            (response_id, question_id, user_id, uploaded_by_id, original_name, stored_name, file_path, file_type, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($answers as &$answer) {

            $questionId = (int)($answer["questionId"] ?? 0);
            $questionLabel = trim($answer["questionLabel"] ?? "");
            $answerText = trim($answer["answer"] ?? "");

            if (!$questionId || !$questionLabel) {
                continue;
            }

            $question = $questionsMap[$questionId] ?? null;

            if ($question && $question["question_type"] === "file") {

                if (
                    isset($files) &&
                    isset($files["name"][$questionId]) &&
                    $files["error"][$questionId] === UPLOAD_ERR_OK
                ) {

                    $originalName = $files["name"][$questionId];
                    $tmpName = $files["tmp_name"][$questionId];
                    $fileType = $files["type"][$questionId];
                    $fileSize = (int)$files["size"][$questionId];

                    $maxMb = (int)($question["max_file_size_mb"] ?? 0);

                    if ($maxMb <= 0 || $maxMb > 5) {
                        $maxMb = 5;
                    }

                    if ($maxMb && $fileSize > $maxMb * 1024 * 1024) {
                        throw new Exception(
                            "Maximum file size allowed is 5MB for: " . $questionLabel
                        );
                    }

                    $folderPath = $uploadRoot . "/" . $user["account_id"] . "/" . $responseId;

                    if (!is_dir($folderPath)) {
                        mkdir($folderPath, 0777, true);
                    }

                    $safeName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);
                    $storedName = time() . "_" . bin2hex(random_bytes(5)) . "_" . $safeName;
                    $filePath = $folderPath . "/" . $storedName;

                    if (!move_uploaded_file($tmpName, $filePath)) {
                        throw new Exception("Failed to upload file: " . $originalName);
                    }

                    $fileStmt->execute([
                        $responseId,
                        $questionId,
                        $user["account_id"],
                        $user["id"],
                        $originalName,
                        $storedName,
                        $filePath,
                        $fileType,
                        $fileSize
                    ]);

                    $answerText = $originalName;
                    $answer["answer"] = $originalName;

                } else {
                    throw new Exception("File is required for: " . $questionLabel);
                }
            }

            // An unticked checkbox question is stored empty, so the export
            // shows a blank rather than a "not agreed".
            if ($question && $question["question_type"] === "checkbox" && $answerText === "") {
                $answerText = "";
                $answer["answer"] = "";
            }

            // Any other blank answer is skipped, as it always has been.
            if ($answerText === "" && ($question && $question["question_type"] !== "checkbox")) {
                continue;
            }

            $answerStmt->execute([
                $responseId,
                $questionId,
                $questionLabel,
                $answerText
            ]);
        }
        unset($answer);

        $pdo->commit();

    } catch (PortalWriteError $e) {
        $pdo->rollBack();
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new PortalWriteError($e->getMessage() ?: "Could not submit the survey", 422);
    }

    // After the commit, never inside it - see the rules at the top of
    // notify.php. notify() swallows its own failures.
    // To whoever built the form, and the owner - not the client's whole team.
    notifyAdminsAboutUser(
        $pdo,
        formResponseAdminIds($pdo, (int) $survey["id"]),
        (int) $user["id"],
        NOTIFY_FORM_SUBMITTED,
        "New response from " . $user["name"],
        $survey["title"] . " has been filled in and submitted.",
        // Opens this response, not the list of every response.
        "response-details.html?id=" . (int) $responseId
    );

    sendSurveyEmail($user, $survey, $answers);
    sendSurveyToSheet($user, $survey, $answers);

    return $responseId;
}

/*
| A copy of every submission to a script that keeps a spreadsheet, for an
| install that wants one: SURVEY_WEBHOOK_URL is its address. There is no
| default, because one install's answers must never reach another's sheet.
*/
function sendSurveyToSheet($user, $survey, $answers)
{
    $url = getenv("SURVEY_WEBHOOK_URL");
    if ($url === false || $url === "") {
        return;
    }

    $appScriptData = [
        "userName" => $user["name"],
        "userEmail" => $user["email"],
        "surveyTitle" => $survey["title"]
    ];

    foreach ($answers as $answer) {
        $question = $answer["questionLabel"] ?? "";
        $answerText = $answer["answer"] ?? "";

        if ($question) {
            $appScriptData[$question] = $answerText;
        }
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($appScriptData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    curl_exec($ch);
    curl_close($ch);
}

/* The same copy by email, to RESPONSE_COPY_EMAIL if this install sets one. */
function sendSurveyEmail($user, $survey, $answers)
{
    $to = getenv("RESPONSE_COPY_EMAIL");
    if ($to === false || $to === "") {
        return;
    }

    $subject = "New Survey Response - " . $survey["title"];

    $csv = "User Name,User Email,Survey Title\n";
    $csv .= csvValue($user["name"]) . "," . csvValue($user["email"]) . "," . csvValue($survey["title"]) . "\n\n";
    $csv .= "Question,Answer\n";

    foreach ($answers as $answer) {
        $question = $answer["questionLabel"] ?? "";
        $answerText = $answer["answer"] ?? "";
        $csv .= csvValue($question) . "," . csvValue($answerText) . "\n";
    }

    $fileName = "survey-response-" . date("Y-m-d-H-i-s") . ".csv";
    $boundary = md5(time());

    // The name comes from Settings: no line breaks may reach the header.
    $fromName = str_replace(["\r", "\n"], "", portalName());
    $headers = "From: " . $fromName . " <" . (string) getenv("SMTP_USER") . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

    $message = "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= "New survey response submitted.\n\n";
    $message .= "User: " . $user["name"] . "\n";
    $message .= "Email: " . $user["email"] . "\n";
    $message .= "Survey: " . $survey["title"] . "\n\n";

    $message .= "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/csv; name=\"" . $fileName . "\"\r\n";
    $message .= "Content-Disposition: attachment; filename=\"" . $fileName . "\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($csv)) . "\r\n";
    $message .= "--" . $boundary . "--";

    @mail($to, $subject, $message, $headers);
}

function csvValue($value)
{
    $value = (string)$value;
    $value = str_replace('"', '""', $value);
    return '"' . $value . '"';
}
