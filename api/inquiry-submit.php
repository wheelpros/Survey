<?php

/*
|--------------------------------------------------------------------------
| Submitting an inquiry answer on someone's behalf - the MCP server only
|--------------------------------------------------------------------------
|
| The AI-facing sibling of the POST half of public-inquiry.php, as
| inquiry-lookup.php is of its GET half. Only the W|ZONE MCP server
| (Wzone-ai/) may call it: the request must carry its shared X-MCP-Key, which
| is also what makes the 'mcp' source tag on a stored answer trustworthy.
|
|   POST /api/inquiry-submit.php
|   { "name": "<slug>", "answers": [{ "fieldId": 11, "value": "..." }],
|     "dry_run": true }
|     -> 200 { success, inquiry: { title }, answers: [{ field_id, label, type, value }] }
|        exactly what would be stored - the MCP server shows this to the
|        person and asks them to confirm before anything is sent
|
|   { ..., "dry_run": false, "submission_key": "<64 hex>" }
|     -> 200 { success, duplicate, message }
|        submission_key is the MCP server's idempotency key: the same key
|        twice stores one answer, and the second call reports duplicate.
|
| Same validation as the web form (api/inquiry-submission.php). Name-only
| links only - legacy single-use ?token= invites stay web-only.
*/

require_once "db.php";
require_once "rate-limit.php";
require_once "inquiry-submission.php";

header("Content-Type: application/json; charset=UTF-8");

// For the MCP server as a whole - it limits each of its own callers far
// more tightly before a request ever gets here.
const MCP_SUBMIT_MAX_PER_MINUTE = 60;
const MAX_ANSWERS = 200;

function reply($status, array $body)
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(405, ["success" => false, "message" => "Only POST is supported"]);
}

if (!isMcpServer()) {
    reply(403, ["success" => false, "message" => "This endpoint is only for the W|ZONE MCP server"]);
}

if (rateLimited($pdo, "inquiry-submit-mcp", MCP_SUBMIT_MAX_PER_MINUTE, MCP_SUBMIT_MAX_PER_MINUTE)) {
    reply(429, ["success" => false, "message" => "Too many submissions right now - please try again in a minute."]);
}

ensureInquiryTables($pdo);

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    reply(400, ["success" => false, "message" => "Expected a JSON body"]);
}

$name = trim((string)($input["name"] ?? ""));
$answers = $input["answers"] ?? [];
$dryRun = ($input["dry_run"] ?? false) === true;
$submissionKey = (string)($input["submission_key"] ?? "");

if (!preg_match('/^[a-z0-9-]{1,200}$/', $name)) {
    reply(400, ["success" => false, "message" => "A valid inquiry name is required"]);
}
if (!is_array($answers) || count($answers) > MAX_ANSWERS) {
    reply(400, ["success" => false, "message" => "answers must be a list of { fieldId, value }"]);
}
if (!$dryRun && !preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
    reply(400, ["success" => false, "message" => "submission_key is required to submit"]);
}

try {

    $inquiry = findInquiryByName($pdo, $name);
    if (!$inquiry) {
        reply(404, ["success" => false, "message" => "No inquiry found with that name"]);
    }
    if (($inquiry["status"] ?? "active") === "inactive") {
        reply(409, ["success" => false, "message" => "This inquiry is not accepting responses right now"]);
    }

    $fields = inquiryFieldsForSubmission($pdo, $inquiry["id"]);
    $checked = validateInquiryAnswers($fields, $answers);

    if ($checked["error"] !== null) {
        reply(422, ["success" => false, "message" => $checked["error"]]);
    }

    if ($dryRun) {
        reply(200, [
            "success" => true,
            "inquiry" => ["title" => $inquiry["title"]],
            "answers" => array_map(function ($field) use ($checked) {
                return [
                    "field_id" => (int)$field["id"],
                    "label" => $field["field_label"],
                    "type" => $field["field_type"],
                    "value" => $checked["answers"][(int)$field["id"]] ?? "",
                ];
            }, $fields),
        ]);
    }

    $stored = storeInquiryResponse($pdo, $inquiry["id"], 0, $fields, $checked["answers"], "mcp", $submissionKey);

    reply($stored["success"] ? 200 : 500, [
        "success" => $stored["success"],
        "duplicate" => $stored["duplicate"],
        "message" => $stored["message"],
    ]);

} catch (PDOException $e) {
    // Never echo $e->getMessage() - it can carry schema/column detail.
    reply(500, ["success" => false, "message" => "Unable to submit right now"]);
}
