<?php

/*
|--------------------------------------------------------------------------
| Download one attachment
|--------------------------------------------------------------------------
|
| GET api/attachment.php?id=12   with the admin's Bearer token
|
| Attachments are staff-only, so only an admin session gets in - a client
| token is refused like no token at all. The admin must also be able to see
| what the file is attached to: the phase's project (projectScopeSql) or the
| post (contentVisibleSql, everything for the roles that see the whole
| library). Anything else is a 404, so nothing confirms a file exists.
|
| The pages fetch this with the token in the header and save the blob, so
| the token never goes in a URL.
|
*/

require_once "db.php";
require_once "auth.php";
require_once "upload-helpers.php";

function attachmentFail($message, $code)
{
    http_response_code($code);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

$token = bearerToken();
if ($token === "") {
    attachmentFail("Unauthorized.", 401);
}

$stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? AND active = 1 LIMIT 1");
try {
    $stmt->execute([$token]);
} catch (PDOException $e) {
    // A database from before the active column.
    $stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
}
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    attachmentFail("Attachments are for the team only.", 403);
}

ensureAttachmentsTable($pdo);

$stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ? LIMIT 1");
$stmt->execute([(int) ($_GET["id"] ?? 0)]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    attachmentFail("File not found.", 404);
}

$visible = false;

if ($file["owner_type"] === "task") {
    ensureProjectTables($pdo);
    [$scopeSql, $scopeParams] = projectScopeSql($admin);
    $stmt = $pdo->prepare("
        SELECT 1 FROM project_tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ?" . ($scopeSql !== "" ? " AND " . $scopeSql : "") . "
        LIMIT 1
    ");
    $stmt->execute(array_merge([(int) $file["owner_id"]], $scopeParams));
    $visible = (bool) $stmt->fetchColumn();
} elseif ($file["owner_type"] === "content") {
    ensureContentColumns($pdo);
    $sql = "SELECT 1 FROM content c WHERE c.id = ?";
    $params = [(int) $file["owner_id"]];
    if (!contentSeesAll($admin)) {
        [$visibleSql, $visibleParams] = contentVisibleSql("c", (int) $admin["id"]);
        $sql .= " AND " . $visibleSql;
        $params = array_merge($params, $visibleParams);
    }
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $visible = (bool) $stmt->fetchColumn();
}

$path = dirname(__DIR__) . "/" . $file["path"];

if (!$visible || strpos($file["path"], "uploads/attachments/") !== 0 || !is_file($path)) {
    attachmentFail("File not found.", 404);
}

$name = cleanAttachmentName($file["original_name"]);

header("Content-Type: " . (ATTACHMENT_SERVE_TYPES[$file["ext"]] ?? "application/octet-stream"));
header("Content-Disposition: attachment; filename=\"" . str_replace(["\r", "\n"], "", $name)
    . "\"; filename*=UTF-8''" . rawurlencode($name));
header("Content-Length: " . filesize($path));
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store");

readfile($path);
exit;
