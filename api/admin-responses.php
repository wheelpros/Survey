<?php

/*
|--------------------------------------------------------------------------
| Submitted responses
|--------------------------------------------------------------------------
|
| Two callers, one query:
|
|   responses.html                 no survey_id - the whole list
|   admin.html "View Responses"    ?survey_id=42 - one form's submissions
|
| The filter was missing before, so the second caller silently received the
| first row of the entire table and always opened the same answers.
|
| Newest first. Where a form has been answered more than once, the caller that
| takes the first row therefore gets the most recent submission rather than an
| arbitrary one.
|
*/

require_once "db.php";

header("Content-Type: application/json");

/*
| Answers are client data, so this endpoint asks who is reading them. It did
| not before - the responses came back to anyone who requested the URL.
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
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
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$surveyId = (int) ($_GET["survey_id"] ?? 0);

$sql = "
    SELECT
        sr.id,
        sr.survey_id,
        sr.user_id,
        sr.submitted_at,
        sr.survey_title,
        u.name,
        u.email
    FROM survey_responses sr
    INNER JOIN users u ON u.id = sr.user_id
";

$params = [];

/*
| The owner sees everything. Everyone else sees responses from the clients
| assigned to them, plus responses to any form they built themselves - the
| "New response" notification goes to a form's creator (notify.php), and it
| would be no use if the creator could not then open what it points at.
| Without this, filtering by survey_id would still hand over the answers of a
| client outside the admin's own pool.
*/

$where = [];

if (!can($currentAdmin, "forms.responses", $pdo)) {
    $sql .= "
        LEFT JOIN admin_user_assignments aua
            ON aua.user_id = sr.user_id
           AND aua.admin_id = ?
        LEFT JOIN surveys s ON s.id = sr.survey_id
    ";
    $params[] = $currentAdmin["id"];

    $where[] = "(aua.admin_id IS NOT NULL OR s.created_by_admin_id = ?)";
    $params[] = $currentAdmin["id"];
}

// The filter that was missing.
if ($surveyId) {
    $where[] = "sr.survey_id = ?";
    $params[] = $surveyId;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY sr.submitted_at DESC, sr.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode([
    "success" => true,
    "responses" => $stmt->fetchAll()
]);
