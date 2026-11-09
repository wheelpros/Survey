<?php

/*
|--------------------------------------------------------------------------
| Team forms - the pages' endpoint
|--------------------------------------------------------------------------
|
| Backs the Team Forms section of admin.html (the lists), the Team Form tab of
| admin-form-builder.html (send) and admin-team-form.html (one form: filling it
| in for whoever it was sent to, everyone's answers for the sender and the Director).
|
|   GET  action=list                  forms sent to me, and the ones I sent
|   GET  action=pending_count         how many are waiting on me (the nav badge)
|   GET  action=recipients            the picker: people and roles  (team_forms.send)
|   GET  action=get&id=               one form, as I may see it
|   GET  action=file&id=              one uploaded answer file
|   GET  action=export&id=            the answers as CSV           (sender, Director)
|   POST action=create                send a new form               (team_forms.send)
|   POST action=submit                send my answers back (multipart)
|   POST action=close|reopen          (sender, Director)
|
| Admins only. The rules are in team-form-writes.php, shared with api/v1.
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";
require_once "team-form-writes.php";

function reply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token = trim(str_replace("Bearer", "", $authHeader));

if (!$token) {
    reply(false, "No token provided", [], 401);
}

// DDL before anything else: create and submit open transactions below.
ensureTeamFormTables($pdo);

$stmt = $pdo->prepare("SELECT id, name, email, role FROM admins WHERE session_token = ? AND active = 1 LIMIT 1");
$stmt->execute([$token]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    reply(false, "Unauthorized token", [], 401);
}
$admin["id"] = (int) $admin["id"];

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$isPost = $_SERVER["REQUEST_METHOD"] === "POST";

// JSON bodies for everything but submit, which is multipart for its files.
$input = [];
if ($isPost && stripos($_SERVER["CONTENT_TYPE"] ?? "", "application/json") !== false) {
    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $input["action"] ?? $action;
}

function requireSender($admin, $pdo)
{
    if (!can($admin, "team_forms.send", $pdo)) {
        reply(false, "You do not have permission to send team forms.", [], 403);
    }
}

try {
    if (!$isPost && $action === "pending_count") {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM team_form_recipients r JOIN team_forms f ON f.id = r.form_id
            WHERE r.admin_id = ? AND r.status = 'pending' AND f.status = 'open'
        ");
        $stmt->execute([$admin["id"]]);
        reply(true, "", ["pending" => (int) $stmt->fetchColumn()]);
    }

    if (!$isPost && $action === "list") {
        reply(true, "", teamFormsListFor($pdo, $admin));
    }

    if (!$isPost && $action === "recipients") {
        requireSender($admin, $pdo);
        reply(true, "", teamFormRecipientChoices($pdo, $admin["id"]));
    }

    if (!$isPost && $action === "get") {
        $form = teamFormFor($pdo, $admin, (int) ($_GET["id"] ?? 0));
        if (!$form) {
            reply(false, "Form not found.", [], 404);
        }
        reply(true, "", ["form" => $form]);
    }

    if (!$isPost && $action === "file") {
        $file = teamFormFileFor($pdo, $admin, (int) ($_GET["id"] ?? 0));
        if (!$file) {
            reply(false, "File not found.", [], 404);
        }
        [$path, $type, $name] = $file;
        header("Content-Type: " . $type);
        header("Content-Disposition: attachment; filename=\"" . str_replace(['"', "\r", "\n"], "", basename($name)) . "\"");
        header("Content-Length: " . filesize($path));
        header("X-Content-Type-Options: nosniff");
        readfile($path);
        exit;
    }

    if (!$isPost && $action === "export") {
        $form = teamFormFor($pdo, $admin, (int) ($_GET["id"] ?? 0));
        if (!$form || !$form["sees_answers"]) {
            reply(false, "Form not found.", [], 404);
        }
        $slug = trim(preg_replace("/[^a-z0-9]+/", "-", strtolower($form["title"])), "-") ?: "team-form";
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"" . $slug . "-answers.csv\"");
        $out = fopen("php://output", "w");
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 only with the BOM
        foreach (teamFormCsvRows($form) as $row) {
            // A cell starting with = + - @ would run as a formula in a spreadsheet.
            fputcsv($out, array_map(function ($v) {
                $v = (string) $v;
                return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
            }, $row));
        }
        fclose($out);
        exit;
    }

    if ($isPost && $action === "create") {
        requireSender($admin, $pdo);

        [$title, $description, $dueDate, $questions] = checkTeamForm(
            $input["title"] ?? "", $input["description"] ?? "", $input["due_date"] ?? "", $input["questions"] ?? []
        );
        $scope = (string) ($input["scope"] ?? "");
        $roles = $scope === "all" ? [] : checkTeamFormRoles($input["roles"] ?? []);
        $ids = $scope === "all" ? [] : (array) ($input["recipient_ids"] ?? []);
        $recipients = teamFormRecipientIds($pdo, $admin["id"], $scope, $roles, $ids);

        $id = createTeamForm($pdo, $admin, $title, $description, $dueDate, $questions, $recipients, $roles);
        reply(true, "Sent to " . count($recipients) . " " . (count($recipients) === 1 ? "person" : "people") . ".", ["id" => $id]);
    }

    if ($isPost && $action === "submit") {
        $formId = (int) ($_POST["form_id"] ?? 0);
        $answers = json_decode($_POST["answers"] ?? "[]", true);
        submitTeamFormResponse($pdo, $admin, $formId, is_array($answers) ? $answers : [], $_FILES["files"] ?? null);
        reply(true, "Your answers were sent.", ["form" => teamFormFor($pdo, $admin, $formId)]);
    }

    if ($isPost && ($action === "close" || $action === "reopen")) {
        $formId = (int) ($input["id"] ?? 0);
        setTeamFormOpen($pdo, $admin, $formId, $action === "reopen");
        reply(true, $action === "close" ? "Form closed." : "Form reopened.", ["form" => teamFormFor($pdo, $admin, $formId)]);
    }

    reply(false, "Unknown action.", [], 400);
} catch (PortalWriteError $e) {
    reply(false, $e->getMessage(), [], $e->status);
}
