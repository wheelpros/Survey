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
| Rather than hard-coding a table list that quietly goes stale every time the
| schema grows, this reads the database's own columns and clears any table
| holding a `user_id`, plus the notification rows that address a user through
| recipient/actor columns. A table added next year is covered without anyone
| remembering to edit this file.
|
| Owner only, and approved accounts only - a pending registration is refused
| here because Reject on the same page already removes those.
|
*/

require_once "db.php";

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

if (($admin["role"] ?? "") !== "owner") {
    deleteReply(false, "Only the owner can delete a client account.");
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];

$userId = (int) ($input["user_id"] ?? 0);
$confirmEmail = strtolower(trim((string) ($input["confirm_email"] ?? "")));

if (!$userId) {
    deleteReply(false, "User ID required.");
}

$stmt = $pdo->prepare("SELECT id, name, email, approved FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    deleteReply(false, "Client not found.");
}

if ((int) $user["approved"] !== 1) {
    deleteReply(false, "This account is still pending. Use Reject instead.");
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
      AND COLUMN_NAME IN ('user_id', 'recipient_type', 'recipient_id', 'actor_type', 'actor_id')
");

$columns->execute([$database]);

$byTable = [];

foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byTable[$row["TABLE_NAME"]][] = $row["COLUMN_NAME"];
}

$deleted = [];

$pdo->beginTransaction();

try {

    foreach ($byTable as $table => $cols) {

        // The users table itself goes last, on its own.
        if ($table === "users") {
            continue;
        }

        if (in_array("user_id", $cols, true)) {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE user_id = ?");
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
            }
        }

        // Notifications address people as a type plus an id, so a bare
        // user_id match would miss them.
        if (in_array("recipient_type", $cols, true) && in_array("recipient_id", $cols, true)) {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE recipient_type = 'user' AND recipient_id = ?");
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
            }
        }

        if (in_array("actor_type", $cols, true) && in_array("actor_id", $cols, true)) {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE actor_type = 'user' AND actor_id = ?");
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
            }
        }
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    $pdo->commit();

} catch (Throwable $e) {

    $pdo->rollBack();

    error_log("delete-client failed for user {$userId}: " . $e->getMessage());

    deleteReply(false, "Could not delete this client. Nothing was removed.");
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
