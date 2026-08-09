```php
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

$method = $_SERVER["REQUEST_METHOD"];


/* =========================================================
   HELPERS
========================================================= */

function prepareChips($chips)
{
    $chips = trim($chips ?? "");

    if (!$chips) {
        return json_encode([]);
    }

    $chipsArray = array_filter(
        array_map("trim", explode(",", $chips))
    );

    return json_encode(array_values($chipsArray));
}


/* =========================================================
   GET
========================================================= */

if ($method === "GET") {

    $singleSurveyId = (int)($_GET["survey_id"] ?? 0);

    /* -----------------------------------------------------
       GET SINGLE SURVEY
    ----------------------------------------------------- */

    if ($singleSurveyId) {

        $stmt = $pdo->prepare("
            SELECT
                surveys.id,
                surveys.assigned_user_id,
                surveys.title,
                surveys.status,
                surveys.created_at,
                users.name AS user_name,
                users.email AS user_email
            FROM surveys
            JOIN users
                ON users.id = surveys.assigned_user_id
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
            SELECT
                id,
                question_text,
                question_type,
                required,
                sort_order,
                chips,
                max_file_size_mb
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


    /* -----------------------------------------------------
       GET USERS
    ----------------------------------------------------- */

    if ($currentAdmin["role"] === "super_admin") {

        $usersStmt = $pdo->query("
            SELECT id, name, email
            FROM users
            WHERE approved = 1
            ORDER BY name ASC
        ");

    } else {

        $usersStmt = $pdo->prepare("
            SELECT
                users.id,
                users.name,
                users.email
            FROM users
            INNER JOIN admin_user_assignments aua
                ON aua.user_id = users.id
            WHERE users.approved = 1
            AND aua.admin_id = ?
            ORDER BY users.name ASC
        ");

        $usersStmt->execute([$currentAdmin["id"]]);
    }


    /* -----------------------------------------------------
       GET SURVEYS
    ----------------------------------------------------- */

    if ($currentAdmin["role"] === "super_admin") {

        $surveysStmt = $pdo->query("
            SELECT
                surveys.id,
                surveys.assigned_user_id,
                surveys.title,
                surveys.status,
                surveys.created_at,
                users.name AS user_name,
                users.email AS user_email
            FROM surveys
            JOIN users
                ON users.id = surveys.assigned_user_id
            ORDER BY surveys.created_at DESC
        ");

    } else {

        /*
         * SEO/Admin users should only see:
         * - Their assigned users
         * - Surveys they are allowed to manage
         *
         * pending_approval is visible here but cannot be
         * approved by normal admins.
         */

        $surveysStmt = $pdo->prepare("
            SELECT
                surveys.id,
                surveys.assigned_user_id,
                surveys.title,
                surveys.status,
                surveys.created_at,
                users.name AS user_name,
                users.email AS user_email
            FROM surveys
            JOIN users
                ON users.id = surveys.assigned_user_id
            INNER JOIN admin_user_assignments aua
                ON aua.user_id = users.id
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


/* =========================================================
   POST / PUT
========================================================= */

if ($method === "POST" || $method === "PUT") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        $input = [];
    }


    /* =====================================================
       SUPER ADMIN APPROVE
    ===================================================== */

    if (
        $method === "PUT" &&
        ($input["action"] ?? "") === "approve"
    ) {

        if ($currentAdmin["role"] !== "super_admin") {

            echo json_encode([
                "success" => false,
                "message" => "Only Super Admin can approve surveys"
            ]);

            exit;
        }

        $surveyId = (int)($input["surveyId"] ?? 0);

        if (!$surveyId) {

            echo json_encode([
                "success" => false,
                "message" => "Survey ID is required"
            ]);

            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE surveys
            SET status = 'pending'
            WHERE id = ?
            AND status = 'pending_approval'
        ");

        $stmt->execute([$surveyId]);

        if ($stmt->rowCount() === 0) {

            echo json_encode([
                "success" => false,
                "message" => "Survey not found or already processed"
            ]);

            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Survey approved and sent to the user"
        ]);

        exit;
    }


    /* =====================================================
       SUPER ADMIN DENY
    ===================================================== */

    if (
        $method === "PUT" &&
        ($input["action"] ?? "") === "deny"
    ) {

        if ($currentAdmin["role"] !== "super_admin") {

            echo json_encode([
                "success" => false,
                "message" => "Only Super Admin can deny surveys"
            ]);

            exit;
        }

        $surveyId = (int)($input["surveyId"] ?? 0);

        if (!$surveyId) {

            echo json_encode([
                "success" => false,
                "message" => "Survey ID is required"
            ]);

            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE surveys
            SET status = 'denied'
            WHERE id = ?
            AND status = 'pending_approval'
        ");

        $stmt->execute([$surveyId]);

        if ($stmt->rowCount() === 0) {

            echo json_encode([
                "success" => false,
                "message" => "Survey not found or already processed"
            ]);

            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Survey denied"
        ]);

        exit;
    }


    /* =====================================================
       CREATE / UPDATE SURVEY
    ===================================================== */

    $surveyId = (int)($input["surveyId"] ?? 0);
    $title = trim($input["title"] ?? "");
    $assignedUserId = (int)($input["assignedUserId"] ?? 0);
    $questions = $input["questions"] ?? [];


    if (
        !$title ||
        !$assignedUserId ||
        !is_array($questions) ||
        !count($questions)
    ) {

        echo json_encode([
            "success" => false,
            "message" => "Survey title, user and questions are required"
        ]);

        exit;
    }


    $pdo->beginTransaction();

    try {

        /* -------------------------------------------------
           CREATE
        ------------------------------------------------- */

        if ($method === "POST") {

            /*
             * Super Admin creates directly as pending.
             *
             * Normal Admin / SEO Admin creates as
             * pending_approval.
             */

            $surveyStatus =
                ($currentAdmin["role"] === "super_admin")
                    ? "pending"
                    : "pending_approval";


            $stmt = $pdo->prepare("
                INSERT INTO surveys
                (
                    title,
                    assigned_user_id,
                    status
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $title,
                $assignedUserId,
                $surveyStatus
            ]);

            $surveyId = $pdo->lastInsertId();

        }


        /* -------------------------------------------------
           UPDATE
        ------------------------------------------------- */

        else {

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


            /*
             * Only pending surveys can be edited.
             */

            if ($survey["status"] !== "pending") {
                throw new Exception(
                    "Only pending surveys can be edited"
                );
            }


            $updateStmt = $pdo->prepare("
                UPDATE surveys
                SET
                    title = ?,
                    assigned_user_id = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $title,
                $assignedUserId,
                $surveyId
            ]);


            /*
             * Remove old questions.
             */

            $deleteStmt = $pdo->prepare("
                DELETE FROM survey_questions
                WHERE survey_id = ?
            ");

            $deleteStmt->execute([$surveyId]);
        }


        /* =================================================
           INSERT QUESTIONS
        ================================================= */

        $qStmt = $pdo->prepare("
            INSERT INTO survey_questions
            (
                survey_id,
                question_text,
                question_type,
                required,
                sort_order,
                chips,
                max_file_size_mb
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");


        foreach ($questions as $index => $question) {

            $questionText =
                trim($question["text"] ?? "");

            $questionType =
                $question["type"] ?? "input";

            $chips =
                $question["chips"] ?? "";


            if (!$questionText) {
                continue;
            }


            /*
             * Allowed question types.
             */

            if (
                !in_array(
                    $questionType,
                    [
                        "input",
                        "textarea",
                        "file",
                        "checkbox"
                    ],
                    true
                )
            ) {

                $questionType = "input";
            }


            $maxFileSizeMb =
                isset($question["maxFileSizeMb"]) &&
                $question["maxFileSizeMb"] !== ""
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


        /* =================================================
           COMMIT
        ================================================= */

        $pdo->commit();


        /* =================================================
           RESPONSE
        ================================================= */

        if ($method === "POST") {

            if ($currentAdmin["role"] === "super_admin") {

                $message =
                    "Survey created successfully";

            } else {

                $message =
                    "Survey sent to Super Admin for approval";
            }

        } else {

            $message =
                "Survey updated successfully";
        }


        echo json_encode([
            "success" => true,
            "message" => $message
        ]);

        exit;


    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        echo json_encode([
            "success" => false,
            "message" =>
                $e->getMessage()
                ?: "Failed to save survey"
        ]);

        exit;
    }
}


/* =========================================================
   INVALID REQUEST
========================================================= */

echo json_encode([
    "success" => false,
    "message" => "Invalid request"
]);
```
