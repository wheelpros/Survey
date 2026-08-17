<?php
// TEMPORARY DEBUG FILE - delete this once the issue is found.
// Hit it the same way you hit admin-users.php: same Authorization header,
// same api/ folder. It shows exactly what the server sees for that token,
// so we can tell whether this is a deploy issue, a role issue, or a data issue.

require_once "db.php";

header("Content-Type: application/json");

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode(["step" => "token", "ok" => false, "message" => "No Authorization header / token received"]);
    exit;
}

$adminStmt = $pdo->prepare("SELECT id, name, role, active FROM admins WHERE session_token = ? LIMIT 1");
$adminStmt->execute([$token]);
$currentAdmin = $adminStmt->fetch();

if (!$currentAdmin) {
    echo json_encode([
        "step" => "resolve_admin",
        "ok" => false,
        "message" => "Token did not match any row in admins.session_token",
        "token_received" => $token
    ]);
    exit;
}

$role = $currentAdmin["role"];
$myId = (int)$currentAdmin["id"];

// Raw rows this admin_id owns in admin_user_assignments.
$rowsStmt = $pdo->prepare("SELECT admin_id, user_id FROM admin_user_assignments WHERE admin_id = ?");
$rowsStmt->execute([$myId]);
$rawAssignmentRows = $rowsStmt->fetchAll();

// The exact scoped query admin-users.php runs for non-owner roles.
$scopedStmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.approved, u.created_at
    FROM users u
    INNER JOIN admin_user_assignments a ON a.user_id = u.id
    WHERE a.admin_id = ?
    ORDER BY u.created_at DESC
");
$scopedStmt->execute([$myId]);
$scopedResult = $scopedStmt->fetchAll();

// For comparison: what the OLD unscoped query would have returned (count only).
$allUsersCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()["c"];

echo json_encode([
    "resolved_admin" => [
        "id" => $myId,
        "name" => $currentAdmin["name"],
        "role" => $role,
        "active" => $currentAdmin["active"]
    ],
    "raw_assignment_rows_for_this_admin_id" => $rawAssignmentRows,
    "scoped_query_result" => $scopedResult,
    "scoped_query_result_count" => count($scopedResult),
    "total_users_in_db_unscoped" => $allUsersCount
], JSON_PRETTY_PRINT);
