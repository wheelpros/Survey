<?php

require_once "db.php";
require_once "notify.php";

header("Content-Type: application/json");

// Before beginTransaction() below: CREATE TABLE is DDL and commits implicitly,
// which would strand the rollBack() in the catch.
ensureNotificationsTable($pdo);

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "No token provided"
    ]);
    exit;
}

$surveyId = (int)($_POST["surveyId"] ?? 0);
$answers = json_decode($_POST["answers"] ?? "[]", true);

if (!$surveyId || !count($answers)) {
    echo json_encode([
        "success" => false,
        "message" => "Survey ID and answers are required"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email, approved
    FROM users
    WHERE session_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || (int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, title, status
    FROM surveys
    WHERE id = ? AND assigned_user_id = ?
    LIMIT 1
");
$stmt->execute([$surveyId, $user["id"]]);
$survey = $stmt->fetch();

if (!$survey) {
    echo json_encode([
        "success" => false,
        "message" => "Survey not found"
    ]);
    exit;
}

if ($survey["status"] === "completed") {
    echo json_encode([
        "success" => false,
        "message" => "This survey has already been submitted"
    ]);
    exit;
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

    $responseStmt = $pdo->prepare("
        INSERT INTO survey_responses 
        (user_id, survey_id, survey_title, status)
        VALUES (?, ?, ?, 'completed')
    ");

    $responseStmt->execute([
        $user["id"],
        $survey["id"],
        $survey["title"]
    ]);

    $responseId = $pdo->lastInsertId();

    $answerStmt = $pdo->prepare("
        INSERT INTO survey_answers 
        (response_id, question_id, question_label, answer)
        VALUES (?, ?, ?, ?)
    ");

    $fileStmt = $pdo->prepare("
        INSERT INTO survey_uploaded_files
        (response_id, question_id, user_id, original_name, stored_name, file_path, file_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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
                isset($_FILES["files"]) &&
                isset($_FILES["files"]["name"][$questionId]) &&
                $_FILES["files"]["error"][$questionId] === UPLOAD_ERR_OK
            ) {
                $originalName = $_FILES["files"]["name"][$questionId];
                $tmpName = $_FILES["files"]["tmp_name"][$questionId];
                $fileType = $_FILES["files"]["type"][$questionId];
                $fileSize = (int)$_FILES["files"]["size"][$questionId];

                $maxMb = (int)($question["max_file_size_mb"] ?? 0);

                if ($maxMb <= 0 || $maxMb > 5) {
                    $maxMb = 5;
                }

                if ($maxMb && $fileSize > $maxMb * 1024 * 1024) {
                    throw new Exception(
                        "Maximum file size allowed is 5MB for: " . $questionLabel
                    );
                }

                $folderPath = $uploadRoot . "/" . $user["id"] . "/" . $responseId;

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

        // --- [التعديل الجديد هنا] ---
        // إذا كان السؤال نوعه Checkbox ولم يتم اختياره، نترك الخانة فارغة تماماً لملف الإكسيل
        if ($question && $question["question_type"] === "checkbox" && $answerText === "") {
            $answerText = ""; // مسحنا "غير موافق" وخليناها فاضية
            $answer["answer"] = ""; 
        }

        // إذا كان السؤال عادياً وفارغاً نستمر في تخطيه كالعادة
        if ($answerText === "" && ($question && $question["question_type"] !== "checkbox")) {
            continue;
        }
        // ---------------------

        $answerStmt->execute([
            $responseId,
            $questionId,
            $questionLabel,
            $answerText
        ]);
    }

    $updateStmt = $pdo->prepare("
        UPDATE surveys
        SET status = 'completed'
        WHERE id = ?
    ");

    $updateStmt->execute([$survey["id"]]);

    $pdo->commit();

    // After the commit, never inside it - see the rules at the top of
    // notify.php. notify() swallows its own failures, so nothing here can
    // reach the catch below and call rollBack() on a closed transaction.
    notifyAdminsForUser(
        $pdo,
        (int) $user["id"],
        NOTIFY_FORM_SUBMITTED,
        "New response from " . $user["name"],
        $survey["title"] . " has been filled in and submitted.",
        "responses.html"
    );

    sendSurveyEmail($user, $survey, $answers);
    
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
        CURLOPT_URL => "https://script.google.com/macros/s/AKfycby8A1MrVhrB0ebOnDp7p2qJtYz9YH8NcM-KW3NIleEX_meVHvWO2rn_jPtdJXiS79MX/exec",
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
    
    echo json_encode([
        "success" => true,
        "message" => "Survey submitted successfully"
    ]);
    exit;

} catch (Exception $e) {

    $pdo->rollBack();

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit;
}

function sendSurveyEmail($user, $survey, $answers) {

    $to = "seowzone@gmail.com";
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

    $headers = "From: W Zone Portal <no-reply@wzone.local>\r\n";
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

function csvValue($value) {
    $value = (string)$value;
    $value = str_replace('"', '""', $value);
    return '"' . $value . '"';
}