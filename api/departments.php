<?php

/*
|--------------------------------------------------------------------------
| Departments - Settings > Departments
|--------------------------------------------------------------------------
|
|   GET                                   every department, with how many
|                                         admins are in each (any admin: the
|                                         pickers need the names)
|   POST {action: create, name}           (admins.manage)
|   POST {action: rename, id, name}
|   POST {action: archive|restore, id}    archived ones leave the pickers;
|                                         admins and reports keep them
|
| Every POST answers with the whole list again, so the panel redraws from it.
| The admin's own department is set on the Add / Edit admin popup
| (admin-update.php, add-user.php).
|
*/

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

require_once "db.php";
require_once "auth.php";
require_once "events.php";

function reply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

ensureDepartmentsTable($pdo);

$p = currentPrincipal($pdo);
if (!$p || $p["kind"] !== "admin") {
    reply(false, "Unauthorized token", [], 401);
}

function departmentsList(PDO $pdo)
{
    $rows = $pdo->query("
        SELECT d.id, d.name, d.active,
               (SELECT COUNT(*) FROM admins a WHERE a.department_id = d.id AND a.role <> 'owner') AS admin_count
        FROM departments d
        ORDER BY d.active DESC, d.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function ($d) {
        return ["id" => (int) $d["id"], "name" => (string) $d["name"], "active" => (int) $d["active"], "admin_count" => (int) $d["admin_count"]];
    }, $rows);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(true, "", ["departments" => departmentsList($pdo)]);
}

if (!can($p, "admins.manage", $pdo)) {
    reply(false, "Only the Director can change departments.", [], 403);
}

$in = json_decode((string) file_get_contents("php://input"), true) ?: [];
$action = (string) ($in["action"] ?? "");
$id = (int) ($in["id"] ?? 0);
$name = trim(preg_replace('/\s+/', " ", (string) ($in["name"] ?? "")));

$current = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT id, name, active FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($action === "create" || $action === "rename") {
    if ($name === "") {
        reply(false, "Enter a department name.");
    }
    if (mb_strlen($name) > 80) {
        reply(false, "Department names can be up to 80 characters.");
    }
    $taken = $pdo->prepare("SELECT 1 FROM departments WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
    $taken->execute([$name, $action === "rename" ? $id : 0]);
    if ($taken->fetchColumn()) {
        reply(false, "There's already a department called \"" . $name . "\".", [], 409);
    }
}

if ($action === "create") {
    $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
    logEvent($pdo, EVENT_DEPARTMENT_CHANGED, "department", (int) $pdo->lastInsertId(), $name, "Added the department \"" . $name . "\"");
} elseif (!$current) {
    reply(false, "That department doesn't exist.", [], 404);
} elseif ($action === "rename") {
    $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?")->execute([$name, $id]);
    logEvent($pdo, EVENT_DEPARTMENT_CHANGED, "department", $id, $name,
        "Renamed the department \"" . $current["name"] . "\" to \"" . $name . "\"");
} elseif ($action === "archive" || $action === "restore") {
    $active = $action === "restore" ? 1 : 0;
    $pdo->prepare("UPDATE departments SET active = ? WHERE id = ?")->execute([$active, $id]);
    logEvent($pdo, EVENT_DEPARTMENT_CHANGED, "department", $id, $current["name"],
        ($active ? "Restored" : "Archived") . " the department \"" . $current["name"] . "\"");
} else {
    reply(false, "Unknown action.", [], 400);
}

$verb = ["create" => "added", "rename" => "renamed", "archive" => "archived", "restore" => "restored"][$action];
reply(true, "Department " . $verb . ".", ["departments" => departmentsList($pdo)]);
