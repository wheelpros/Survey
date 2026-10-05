<?php

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

require_once "rate-limit.php";
require_once "inquiry-submission.php";

ensureInquiryTables($pdo);

// Submissions per visitor per minute. Nobody filling the form in by hand gets
// near it; a script posting leads in a loop does. Reading the form is not
// limited here - that is a page load.
const SUBMIT_MAX_PER_IP = 10;

$method = $_SERVER["REQUEST_METHOD"];

/*
|------------------------------------------------------------------------------
| The public consultation form
|------------------------------------------------------------------------------
|
| A link is the inquiry's name and nothing else:
|
|     inquiry.html?name=free-30-minute-business-growth-consultation
|
| One link per inquiry, shared with as many people as you like, open for as
| long as the inquiry's status says active. There is no per-person token and
| nothing expires on a clock - closing an inquiry is what closes its link.
|
| That is a deliberate trade. The link is no longer single-use, so anyone
| holding it can submit, and submit again; the inquiry's Status is the only
| thing standing between a link and an open form.
|
| Links handed out before this carried a token instead (?token=, and ?ref=
| before that). Those still resolve, and keep their old single-use behaviour -
| a token that has been answered stays answered.
|
*/

function findByToken($pdo, $token)
{
    $stmt = $pdo->prepare("
        SELECT
            inquiry_invites.id AS invite_id, inquiry_invites.status AS invite_status,
            inquiries.id, inquiries.title, inquiries.intro_text, inquiries.status, inquiries.slug
        FROM inquiry_invites
        LEFT JOIN inquiries ON inquiries.id = inquiry_invites.inquiry_id
        WHERE inquiry_invites.slug = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/*
| Resolves whichever half of a link was given. Returns the inquiry, plus the
| invite when one was named - the caller needs it to claim a legacy token.
*/
function resolveLink($pdo, $name, $token)
{
    if ($token !== "") {
        $row = findByToken($pdo, $token);

        if (!$row || !$row["title"]) {
            return ["error" => "This link is not valid"];
        }

        // A name alongside a token still has to agree with it.
        if ($name !== "" && $row["slug"] !== $name) {
            return ["error" => "This link is not valid"];
        }

        if ($row["invite_status"] === "answered") {
            return ["error" => "This link has already been used"];
        }

        return ["inquiry" => $row, "inviteId" => (int)$row["invite_id"]];
    }

    if ($name === "") {
        return ["error" => "Missing link"];
    }

    $inquiry = findInquiryByName($pdo, $name);

    if (!$inquiry) {
        return ["error" => "This link is not valid"];
    }

    return ["inquiry" => $inquiry, "inviteId" => 0];
}

function closedMessage($inquiry)
{
    return ($inquiry["status"] ?? "active") === "inactive"
        ? "This inquiry is not accepting responses right now"
        : null;
}

if ($method === "GET") {

    $name = trim($_GET["name"] ?? "");
    $token = trim($_GET["token"] ?? $_GET["slug"] ?? "");

    /* Branding, not inquiry: the public website address the owner set on
       settings.html, which is what the logo at the top of the page links to.
       Already normalised to an http(s) address, or an empty string when there
       is nothing safe to link to - the page leaves the logo as a plain image
       in that case. */
    $site = ["website_url" => publicWebsiteUrl($pdo)];

    $link = resolveLink($pdo, $name, $token);

    if (isset($link["error"])) {
        echo json_encode([
            "success" => false,
            "message" => $link["error"],
            "site" => $site
        ]);
        exit;
    }

    $closed = closedMessage($link["inquiry"]);

    if ($closed) {
        echo json_encode([
            "success" => false,
            "message" => $closed,
            "site" => $site
        ]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, options, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$link["inquiry"]["id"]]);

    echo json_encode([
        "success" => true,
        "inquiry" => [
            "title" => $link["inquiry"]["title"],
            "intro_text" => $link["inquiry"]["intro_text"]
        ],
        "fields" => $fieldsStmt->fetchAll(),
        "site" => $site
    ]);
    exit;
}

if ($method === "POST") {

    if (rateLimited($pdo, "inquiry-submit-web", SUBMIT_MAX_PER_IP, SUBMIT_MAX_PER_IP)) {
        http_response_code(429);
        echo json_encode(["success" => false, "message" => "Too many submissions - please wait a minute and try again."]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $name = trim($input["name"] ?? "");
    $token = trim($input["token"] ?? $input["slug"] ?? "");
    $answers = $input["answers"] ?? [];

    $link = resolveLink($pdo, $name, $token);

    if (isset($link["error"])) {
        echo json_encode(["success" => false, "message" => $link["error"]]);
        exit;
    }

    $inquiry = $link["inquiry"];
    $inviteId = $link["inviteId"];

    $closed = closedMessage($inquiry);

    if ($closed) {
        echo json_encode(["success" => false, "message" => $closed]);
        exit;
    }

    // The rules and the write are shared with api/inquiry-submit.php (the
    // MCP server's way in) - see api/inquiry-submission.php.
    $fields = inquiryFieldsForSubmission($pdo, $inquiry["id"]);

    $checked = validateInquiryAnswers($fields, $answers);

    if ($checked["error"] !== null) {
        echo json_encode(["success" => false, "message" => $checked["error"]]);
        exit;
    }

    $stored = storeInquiryResponse($pdo, $inquiry["id"], $inviteId, $fields, $checked["answers"], "web");

    echo json_encode(["success" => $stored["success"], "message" => $stored["message"]]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
