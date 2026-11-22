<?php

/*
|--------------------------------------------------------------------------
| Activity reports - writing (the pages' endpoint)
|--------------------------------------------------------------------------
|
| POST, JSON {action, ...}:
|   update_activities {id, activities: [{id, status, outcome, count_value}]}
|                       the author's in-place edits, only the changed ones
|   add_activity {id, activity: {title, description, count_unit}}
|   remove_activity {id, activity_id}
|                       the author's own extra activities on a report someone
|                       else planned (reports.self); removable until worked on
|   end_of_day {id, outstanding, priorities, challenges, challenge_priority}
|                       the author's end-of-day note, while it's open
|   close {id, ...end_of_day fields?}
|                       the author sends an open report, from any day
|                       (saving the note with it)
|   plan {...}          a new report (any number a day); with id, change a
|                       plan. Your own takes reviewer_ids and each activity's
|                       status, outcome, count_value; for others, you review it
|   delete {id}         the planner, before anything happened
|   review_activity {activity_id, state: approved|changes, feedback}
|   review {id, feedback}
|   template_save {id?, name, department_id, reviewer_id, activities}
|   template_delete {id}
|
| POST, multipart: action=update_files, report={"id", "keep_file_ids"} and
| files[] - the author's files (up to 5, 8 MB each; documents and photos).
|
| The rules are in activity-report-lib.php, shared with the dashboard and api/v1.
|
*/

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

require_once "db.php";
require_once "auth.php";
requirePage($pdo, "daily_reports"); // "Open X" in Settings > Permissions
require_once "activity-report-lib.php";

function reply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(false, "Use POST.", [], 405);
}

// DDL before anything else: the writes below open transactions.
ensureDailyReportTables($pdo);
ensureAttachmentsTable($pdo);

$p = currentPrincipal($pdo);
// A browser session only: an AI connection comes in through api/v1.
if (!$p || $p["kind"] !== "admin" || $p["via"] !== "session") {
    reply(false, "Unauthorized token", [], 401);
}
$p["id"] = (int) $p["id"];

$multipart = stripos($_SERVER["CONTENT_TYPE"] ?? "", "multipart/form-data") !== false;
if ($multipart) {
    $action = (string) ($_POST["action"] ?? "");
    $in = json_decode((string) ($_POST["report"] ?? "{}"), true) ?: [];
} else {
    $in = json_decode((string) file_get_contents("php://input"), true) ?: [];
    $action = (string) ($in["action"] ?? "");
}

try {
    switch ($action) {
        case "update_activities":
            reply(true, drUpdateActivities($pdo, $p, (int) ($in["id"] ?? 0), $in["activities"] ?? []));

        case "add_activity":
            reply(true, drAddOwnActivity($pdo, $p, (int) ($in["id"] ?? 0), is_array($in["activity"] ?? null) ? $in["activity"] : []));

        case "remove_activity":
            reply(true, drRemoveOwnActivity($pdo, $p, (int) ($in["id"] ?? 0), (int) ($in["activity_id"] ?? 0)));

        case "end_of_day":
            reply(true, drSaveEndOfDay($pdo, $p, (int) ($in["id"] ?? 0), $in));

        case "close":
            [, $message] = drClose($pdo, $p, (int) ($in["id"] ?? 0), $in);
            reply(true, $message);

        case "update_files":
            $gone = drUpdateFiles($pdo, $p, (int) ($in["id"] ?? 0), $in["keep_file_ids"] ?? [], uploadedFiles("files"));
            deleteUploadedFiles($gone);
            reply(true, "Files saved.");

        case "plan":
            [$ids, $message] = drPlan($pdo, $p, $in);
            reply(true, $message, ["ids" => $ids]);

        case "delete":
            [, $message, $paths] = drDelete($pdo, $p, (int) ($in["id"] ?? 0));
            deleteUploadedFiles($paths);
            reply(true, $message);

        case "review_activity":
            [, , $message] = drReviewActivity($pdo, $p, (int) ($in["activity_id"] ?? 0), (string) ($in["state"] ?? ""), $in["feedback"] ?? "");
            reply(true, $message);

        case "review":
            [, $message] = drReviewReport($pdo, $p, (int) ($in["id"] ?? 0), $in["feedback"] ?? "");
            reply(true, $message);

        case "template_save":
            [$id, $message] = drTemplateSave($pdo, $p, $in);
            reply(true, $message, ["id" => $id]);

        case "template_delete":
            reply(true, drTemplateDelete($pdo, $p, (int) ($in["id"] ?? 0)));
    }
    reply(false, "Unknown action.", [], 400);
} catch (PortalWriteError $e) {
    reply(false, $e->getMessage(), [], $e->status);
} catch (PDOException $e) {
    error_log("activity-report-writes.php: " . $e->getMessage());
    reply(false, "The report couldn't be saved. Please try again.", [], 500);
}
