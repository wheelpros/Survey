<?php

/*
|--------------------------------------------------------------------------
| Delete a client, permanently
|--------------------------------------------------------------------------
|
| Not a deactivation and not a soft flag: the account and everything tied to
| it leave the database. This exists for privacy requests, so a row left
| behind in some side table would defeat the point.
|
| Two passes. CLIENT_TENANT_COLUMNS (db.php) names the records whose column
| isn't simply `user_id`, and they go with what hangs off them - a form's
| questions and answers, a project's phases and people. Then the database's own
| columns are read and any other table holding a `user_id` is cleared, so a
| table added next year is covered without anyone remembering to edit this file.
|
| admin_events and mcp_audit_log stay: they are the record of who did what, not
| the client's data, and they prune on their own schedule.
|
| Owner only, and approved accounts only - a pending registration is refused
| here because Reject on the same page already removes those.
|
*/

require_once "db.php";
require_once "events.php";
require_once "upload-helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function deleteReply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if ($token === "") {
    deleteReply(false, "Unauthorized");
}

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    deleteReply(false, "Unauthorized");
}

if (!can($admin, "clients.delete", $pdo)) {
    deleteReply(false, "Only the Director can delete a client account.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];

$userId = (int) ($input["user_id"] ?? 0);
$confirmEmail = strtolower(trim((string) ($input["confirm_email"] ?? "")));

if (!$userId) {
    deleteReply(false, "User ID required.");
}

$stmt = $pdo->prepare("SELECT id, name, email, approved, account_owner_id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    deleteReply(false, "Client not found.");
}

if ((int) $user["approved"] !== 1) {
    deleteReply(false, "This account is still pending. Use Reject instead.");
}

if (!empty($user["account_owner_id"])) {
    deleteReply(false, "This person is on a client's team. Remove them from the team instead.");
}

/*
| The page sends back the email it displayed. If it does not match the row,
| the list was stale and this is about to delete the wrong person - which is
| not recoverable, so it stops instead.
*/

if ($confirmEmail === "" || $confirmEmail !== strtolower(trim($user["email"]))) {
    deleteReply(false, "Confirmation did not match. Reload the page and try again.");
}

/*
|--------------------------------------------------------------------------
| Everything that points at this user
|--------------------------------------------------------------------------
*/

$database = $pdo->query("SELECT DATABASE()")->fetchColumn();

$columns = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
");

$columns->execute([$database]);

$byTable = [];

foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byTable[$row["TABLE_NAME"]][] = $row["COLUMN_NAME"];
}

$deleted = [];
$files = [];

/** Runs one DELETE, if its table exists on this install, and counts what went. */
function eraseRows(PDO $pdo, array $byTable, array &$deleted, $table, $where, array $params)
{
    if (!isset($byTable[$table]) || !$params) {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE {$where}");
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
    }
}

/** One column of the rows matching $where, or nothing if the table is absent. */
function clientRowValues(PDO $pdo, array $byTable, $table, $column, $where, array $params)
{
    if (!isset($byTable[$table]) || !$params) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE {$where}");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function placeholders(array $values)
{
    return implode(",", array_fill(0, count($values), "?"));
}

$eventWho = eventClientName($pdo, $userId);

$pdo->beginTransaction();

try {

    // Uploads moved here by a merge stay in the old account's folder.
    $surveyUploads = clientRowValues($pdo, $byTable, "survey_uploaded_files", "file_path", "user_id = ?", [$userId]);

    // Forms, with their questions, answers and upload records.
    $surveyIds = clientRowValues($pdo, $byTable, "surveys", "id", "assigned_user_id = ?", [$userId]);
    $responseIds = clientRowValues($pdo, $byTable, "survey_responses", "id", "user_id = ?", [$userId]);

    if ($surveyIds) {
        $responseIds = array_values(array_unique(array_merge($responseIds,
            clientRowValues($pdo, $byTable, "survey_responses", "id", "survey_id IN (" . placeholders($surveyIds) . ")", $surveyIds))));
    }

    if ($responseIds) {
        $in = placeholders($responseIds);
        eraseRows($pdo, $byTable, $deleted, "survey_answers", "response_id IN ($in)", $responseIds);
        eraseRows($pdo, $byTable, $deleted, "survey_uploaded_files", "response_id IN ($in)", $responseIds);
        eraseRows($pdo, $byTable, $deleted, "survey_responses", "id IN ($in)", $responseIds);
    }

    if ($surveyIds) {
        eraseRows($pdo, $byTable, $deleted, "survey_questions", "survey_id IN (" . placeholders($surveyIds) . ")", $surveyIds);
    }

    // Projects, with their phases, people and files.
    $projectIds = clientRowValues($pdo, $byTable, "projects", "id", "client_id = ?", [$userId]);

    if ($projectIds) {
        $in = placeholders($projectIds);

        $files = array_merge($files, clientRowValues($pdo, $byTable, "projects", "image_path", "id IN ($in)", $projectIds));

        $taskStmt = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id IN ($in)");
        $taskStmt->execute($projectIds);

        foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $files = array_merge(
                $files,
                array_filter([$task["image_path"] ?? null]),
                taskGallery($task),
                deleteAttachmentRows($pdo, "task", $task["id"]),
                deleteAttachmentRows($pdo, CLIENT_PHASE_FILES_OWNER, $task["id"])
            );

            eraseRows($pdo, $byTable, $deleted, "project_task_teammates", "task_id = ?", [$task["id"]]);
            eraseRows($pdo, $byTable, $deleted, "project_task_assignments", "task_id = ?", [$task["id"]]);
        }

        eraseRows($pdo, $byTable, $deleted, "project_tasks", "project_id IN ($in)", $projectIds);
        eraseRows($pdo, $byTable, $deleted, "project_members", "project_id IN ($in)", $projectIds);
    }

    // The tenant columns themselves: everything above has let go of them.
    foreach (CLIENT_TENANT_COLUMNS as $table => $column) {
        eraseRows($pdo, $byTable, $deleted, $table, "`{$column}` = ?", [$userId]);
    }

    // The rest is per person, so it goes for the owner and each teammate alike.
    $teammateIds = clientRowValues($pdo, $byTable, "users", "id", "account_owner_id = ?", [$userId]);

    foreach (array_merge([$userId], $teammateIds) as $personId) {

    // Notifications address people as a kind plus an id, so a bare user_id
    // match would miss them - and id 5 can be an admin as well as this client.
        eraseRows($pdo, $byTable, $deleted, "notifications", "recipient_kind = 'user' AND recipient_id = ?", [$personId]);
        eraseRows($pdo, $byTable, $deleted, "notifications", "actor_kind = 'user' AND actor_id = ?", [$personId]);

        // AI connections, and anything still able to act through one.
        $grantIds = clientRowValues($pdo, $byTable, "mcp_grants", "id", "principal_kind = 'user' AND principal_id = ?", [$personId]);

        if ($grantIds) {
        $in = placeholders($grantIds);
        eraseRows($pdo, $byTable, $deleted, "mcp_tokens", "grant_id IN ($in)", $grantIds);
        eraseRows($pdo, $byTable, $deleted, "oauth_codes", "grant_id IN ($in)", $grantIds);
        eraseRows($pdo, $byTable, $deleted, "mcp_grants", "id IN ($in)", $grantIds);
        }

        eraseRows($pdo, $byTable, $deleted, "mcp_confirmations", "principal_kind = 'user' AND principal_id = ?", [$personId]);
    }

    eraseRows($pdo, $byTable, $deleted, "client_invites", "account_id = ?", [$userId]);

    $files = array_merge($files, clientRowValues($pdo, $byTable, "users", "profile_image", "account_owner_id = ?", [$userId]));
    eraseRows($pdo, $byTable, $deleted, "users", "account_owner_id = ?", [$userId]);

    // Any other table holding a user_id, including ones added later. The users
    // table itself goes last, on its own.
    foreach ($byTable as $table => $cols) {
        if ($table !== "users" && in_array("user_id", $cols, true)) {
            eraseRows($pdo, $byTable, $deleted, $table, "user_id = ?", [$userId]);
        }
    }

    $files = array_merge($files, clientRowValues($pdo, $byTable, "users", "profile_image", "id = ?", [$userId]));

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    logEvent($pdo, EVENT_CLIENT_DELETED, "user", $userId, $eventWho,
        "Permanently deleted " . $eventWho . " (" . $user["email"] . ") and everything of theirs", $userId);

    $pdo->commit();

} catch (Throwable $e) {

    $pdo->rollBack();

    error_log("delete-client failed for user {$userId}: " . $e->getMessage());

    deleteReply(false, "Could not delete this client. Nothing was removed.");
}

/*
| Files last, once the rows are certainly gone: a failed delete must not leave
| records pointing at files that no longer exist. A client's form uploads all
| live under one folder named for their id (survey-submit.php).
*/
deleteUploadedFiles($files);

$surveyRoot = realpath(dirname(__DIR__) . "/uploads/survey-files");

foreach ($surveyUploads as $path) {
    $full = realpath((string) $path);

    if ($surveyRoot && $full && strpos($full, $surveyRoot . DIRECTORY_SEPARATOR) === 0) {
        @unlink($full);
    }
}

$surveyFolder = dirname(__DIR__) . "/uploads/survey-files/" . $userId;

if (is_dir($surveyFolder)) {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($surveyFolder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($surveyFolder);
}

// Logged deliberately: a permanent deletion is worth a trail of who did it
// and when, even though the account itself is gone.
error_log(sprintf(
    "client deleted: user #%d (%s) by admin #%d; rows removed: %s",
    $userId,
    $user["email"],
    $admin["id"],
    json_encode($deleted)
));

deleteReply(true, $user["name"] . " has been permanently deleted.", [
    "user_id" => $userId
]);
