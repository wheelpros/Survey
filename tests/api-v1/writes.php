<?php

/*
| Writes - required by run.php after the read matrix, sharing its scope
| ($pdo, the tokens, check(), get(), post()...). Two halves:
|
|   1. The browser endpoints whose writes moved into shared functions
|      (survey-writes.php, meeting-writes.php, task-writes.php,
|      inquiry-templates.php) still behave as they did.
|   2. api/v1/changes.php: prepare -> confirm, and everything that must
|      stop a change - scope, visibility, a stale state, a used, expired,
|      altered or borrowed confirmation.
*/

/** How many notifications of $event $kind #$id has. */
function notes(PDO $pdo, $kind, $id, $event)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_kind = ? AND recipient_id = ? AND event_type = ?");
    $stmt->execute([$kind, $id, $event]);
    return (int) $stmt->fetchColumn();
}

function one(PDO $pdo, $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/** GET on a browser endpoint (get() is for api/v1): [status, body]. */
function pageGet($path, $token)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true, "timeout" => 10],
    ]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
}

/** The admin ids on a post, lowest first. */
function teamOf(PDO $pdo, $contentId)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM content_assignees WHERE content_id = ? ORDER BY admin_id");
    $stmt->execute([(int) $contentId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function prepare($token, $tool, array $args, array $headers = [])
{
    return post("api/v1/changes.php", $token, ["action" => "prepare", "tool" => $tool, "args" => (object) $args], $headers);
}

function confirm($token, $prepared, array $headers = [], $summary = null)
{
    return post("api/v1/changes.php", $token, [
        "action" => "confirm",
        "confirmation_token" => $prepared["data"]["confirmation_token"] ?? "",
        "summary" => $summary ?? ($prepared["data"]["summary"] ?? ""),
    ], $headers);
}

/** prepare + confirm, asserting both succeed; returns the confirm reply. */
function change($label, $token, $tool, array $args)
{
    [$status, $prep] = prepare($token, $tool, $args);
    check("$label: prepared", $status === 200 && isset($prep["data"]["confirmation_token"]), "HTTP $status " . json_encode($prep));
    [$status, $done] = confirm($token, $prep);
    check("$label: confirmed", $status === 200 && ($done["data"]["done"] ?? false) === true, "HTTP $status " . json_encode($done));
    return [$prep, $done];
}

function refusedChange($label, $token, $tool, array $args, $status)
{
    [$got, $body] = prepare($token, $tool, $args);
    check($label, $got === $status && ($body["ok"] ?? null) === false, "HTTP $got " . json_encode($body));
}

$tomorrow = day("+1");

// ── 1. Browser endpoints on the shared writes ───────────────────────────

$questions = [
    ["text" => "Goals?", "type" => "textarea", "chips" => ""],
    ["text" => "Channels", "type" => "checkbox", "chips" => "Instagram, TikTok"],
    ["text" => "", "type" => "input"],
];

[, $r] = post("api/admin-surveys.php", $sa, ["title" => "Sam's page form", "assignedUserId" => 10, "questions" => $questions]);
$form = one($pdo, "SELECT * FROM surveys WHERE title = 'Sam''s page form'");
check("page: a super admin's form waits for review", ($r["success"] ?? false) && $form && $form["status"] === "pending_review", json_encode($r));
$qs = $pdo->query("SELECT question_text, chips, sort_order FROM survey_questions WHERE survey_id = " . (int) $form["id"] . " ORDER BY sort_order")->fetchAll();
check("page: questions saved as before", count($qs) === 2 && $qs[1]["chips"] === '["Instagram","TikTok"]' && (int) $qs[1]["sort_order"] === 2, json_encode($qs));
check("page: reviewers told, the author not", notes($pdo, "admin", 1, "form_awaiting_review") === 1
    && notes($pdo, "admin", 3, "form_awaiting_review") === 1 && notes($pdo, "admin", 2, "form_awaiting_review") === 0);

[, $r] = post("api/admin-surveys.php", $sa, ["title" => "Nope", "assignedUserId" => 11, "questions" => $questions]);
check("page: not for someone else's client", ($r["message"] ?? "") === "That user is not in your assigned list", json_encode($r));

[, $r] = post("api/admin-surveys.php", $am, ["title" => "Amy's page form", "assignedUserId" => 10, "questions" => $questions]);
check("page: a reviewer's own form goes straight out", ($r["message"] ?? "") === "Form created and sent to the client"
    && one($pdo, "SELECT status FROM surveys WHERE title = 'Amy''s page form'")["status"] === "pending"
    && notes($pdo, "user", 10, "form_approved") === 1, json_encode($r));

$r = put("api/admin-surveys.php", $sa, ["surveyId" => (int) $form["id"], "title" => "Sam's page form v2", "assignedUserId" => 10, "questions" => $questions]);
check("page: an edit reopens review and tells reviewers again", ($r["success"] ?? false) === true
    && notes($pdo, "admin", 1, "form_awaiting_review") === 2, json_encode($r));

[, $r] = post("api/admin-survey-review.php", $am, ["surveyId" => (int) $form["id"], "action" => "reject"]);
check("page: returning needs a note", ($r["message"] ?? "") === "Please add a short note explaining the rejection", json_encode($r));
[, $r] = post("api/admin-survey-review.php", $sa, ["surveyId" => (int) $form["id"], "action" => "approve"]);
check("page: only reviewers decide", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/admin-survey-review.php", $am, ["surveyId" => (int) $form["id"], "action" => "approve"]);
check("page: approving releases it and tells both ends", ($r["message"] ?? "") === "Survey approved and sent to the user"
    && notes($pdo, "user", 10, "form_approved") === 2 && notes($pdo, "admin", 2, "form_approved") === 1, json_encode($r));
[, $r] = post("api/admin-survey-review.php", $am, ["surveyId" => (int) $form["id"], "action" => "approve"]);
check("page: deciding twice is refused", ($r["success"] ?? true) === false, json_encode($r));

[, $r] = post("api/calendar.php?action=create_admin_request", $am, ["client" => "Acme Ltd", "date" => $tomorrow, "time" => "09:30", "topic" => "Page meeting"]);
check("page: a meeting request reaches the company's accounts", ($r["message"] ?? "") === "Request sent to 1 client."
    && one($pdo, "SELECT COUNT(*) AS n FROM appointments WHERE topic = 'Page meeting' AND user_id = 10 AND admin_id = 3")["n"] == 1
    && notes($pdo, "user", 10, "appointment_request") === 1, json_encode($r));
[, $r] = post("api/calendar.php?action=create_admin_request", $am, ["client" => "Acme Ltd", "date" => "", "time" => "", "topic" => ""]);
check("page: a request needs a date, time and topic", ($r["message"] ?? "") === "Pick a date and time, and say what the meeting is about.", json_encode($r));
[, $r] = post("api/calendar.php?action=respond_request", $am2, ["id" => 400, "status" => "approved"]);
check("page: not someone else's client's request", ($r["message"] ?? "") === "That request is not yours to answer.", json_encode($r));

[, $r] = post("api/project-tasks.php", $am2, ["project_id" => 501, "title" => "Page update", "status" => "published", "link" => "beta.example"], [], true);
$task = one($pdo, "SELECT * FROM project_tasks WHERE title = 'Page update'");
check("page: a published task tells the client once", ($r["message"] ?? "") === "Task published." && $task
    && $task["link"] === "https://beta.example" && notes($pdo, "user", 11, "project_update") === 1, json_encode($r));
[, $r] = post("api/project-tasks.php", $am2, ["id" => (int) $task["id"], "title" => "Page update, edited", "status" => "published"], [], true);
check("page: editing it doesn't tell them again", ($r["success"] ?? false) && notes($pdo, "user", 11, "project_update") === 1, json_encode($r));
[$status, $r] = post("api/project-tasks.php", $seo, ["id" => (int) $task["id"], "title" => "Hijack", "status" => "published"], [], true);
check("page: a member can't rewrite a teammate's task", $status === 403, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/project-tasks.php", $am2, ["project_id" => 501, "title" => "Bad link", "link" => "javascript:alert(1)"], [], true);
check("page: a script link is refused", $status === 400, "HTTP $status " . json_encode($r));

[, $r] = post("api/admin-inquiries.php", $owner, ["title" => "Page inquiry", "fields" => [["label" => "Name", "type" => "input", "required" => true]]]);
check("page: the owner creates an inquiry", ($r["success"] ?? false) && ($r["name"] ?? "") === "page-inquiry", json_encode($r));
[, $r] = post("api/admin-inquiries.php", $am, ["title" => "Not mine", "fields" => [["label" => "Name"]]]);
check("page: only the owner creates inquiries", ($r["message"] ?? "") === "You do not have permission to create inquiries", json_encode($r));
$pageInquiry = (int) one($pdo, "SELECT id FROM inquiries WHERE title = 'Page inquiry'")["id"];
$r = put("api/admin-inquiries.php", $owner, ["id" => $pageInquiry, "title" => "Page inquiry v2", "status" => "inactive",
    "fields" => [["label" => "Name", "type" => "input", "required" => true], ["label" => "Budget", "type" => "textarea"]]]);
check("page: the owner edits an inquiry, and its name follows the title", ($r["success"] ?? false) && ($r["name"] ?? "") === "page-inquiry-v2"
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM inquiry_fields WHERE inquiry_id = ?", [$pageInquiry])["n"] === 2
    && one($pdo, "SELECT status FROM inquiries WHERE id = ?", [$pageInquiry])["status"] === "inactive", json_encode($r));
$r = put("api/admin-inquiries.php", $owner, ["id" => 700, "title" => "Rewritten", "status" => "active", "accountManagerId" => 3,
    "reference" => "PAGE-1", "fields" => [["label" => "Only this"]]]);
check("page: an answered inquiry keeps its questions", ($r["locked"] ?? false) === true
    && one($pdo, "SELECT title, reference FROM inquiries WHERE id = 700") == ["title" => "Free consult", "reference" => "PAGE-1"]
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM inquiry_fields WHERE inquiry_id = 700")["n"] === 2, json_encode($r));

[$status, $r] = post("api/admin-content.php", $seo, ["title" => "Page post", "client" => "Acme Ltd", "status" => "draft"], [], true);
check("page: an Admin can't create content", $status === 403
    && !one($pdo, "SELECT id FROM content WHERE title = 'Page post'"), "HTTP $status " . json_encode($r));

[, $r] = post("api/admin-content.php", $am, ["title" => "Page post", "link" => "acme.example", "status" => "draft"], [], true);
$post = one($pdo, "SELECT status, link, client, content_type FROM content WHERE title = 'Page post'");
check("page: a title is all a new post needs, and links get https",
    $post && $post["status"] === "draft" && $post["link"] === "https://acme.example" && $post["client"] === null && $post["content_type"] === "",
    json_encode([$r, $post]));

[$status, $r] = post("api/admin-content.php", $am, ["title" => "Page post 2", "publish_now" => "1", "status" => "published"], [], true);
check("page: nothing goes live without a client", $status === 400 && !one($pdo, "SELECT id FROM content WHERE title = 'Page post 2'"),
    "HTTP $status " . json_encode($r));

// A super admin creates too, and picks the teammates: only the client's team
// (Acme: Sam, Amy, Sean - not Andy, Beta's) gets on, never the deactivated
// or the one saving, and only they hear about it. Nothing a super admin saves
// is scheduled or published.
[$status, $r] = pageGet("api/admin-content.php?candidates=1&client=" . urlencode("Acme Ltd"), $sa);
check("page: the picker offers the client's team", array_column($r["admins"] ?? [], "id") === [3, 2, 5], "HTTP $status " . json_encode($r));
[, $r] = pageGet("api/admin-content.php?candidates=1", $sa);
check("page: with no client, anyone who can be assigned", array_column($r["admins"] ?? [], "id") === [3, 4, 2, 5], json_encode($r));
[, $r] = post("api/admin-content.php", $sa, ["title" => "Team post", "client" => "Acme Ltd", "publish_now" => "1", "status" => "published",
    "member_ids_sent" => "1", "member_ids" => [5, 6, 1, 3, 2, 4]], [], true);
$teamPost = one($pdo, "SELECT id, status FROM content WHERE title = 'Team post'");
$teamOf = function ($id) use ($pdo) {
    $stmt = $pdo->prepare("SELECT admin_id FROM content_assignees WHERE content_id = ? ORDER BY admin_id");
    $stmt->execute([$id]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
};
check("page: a super admin creates a draft with teammates", ($r["success"] ?? false) && $teamPost && $teamPost["status"] === "draft"
    && $teamOf($teamPost["id"]) === [3, 5], json_encode([$r, $teamPost]));
check("page: the teammates are told, nobody else", notes($pdo, "admin", 5, "content_assigned") === 1
    && notes($pdo, "admin", 3, "content_assigned") === 1 && notes($pdo, "admin", 2, "content_assigned") === 0
    && notes($pdo, "admin", 4, "content_assigned") === 0);

// The assignee fills it in but can't change who is on it.
[, $r] = post("api/admin-content.php", $seo, ["id" => (int) $teamPost["id"], "title" => "Team post", "client" => "Acme Ltd",
    "member_ids_sent" => "1"], [], true);
check("page: an assignee edits but keeps the team", ($r["success"] ?? false) && $teamOf($teamPost["id"]) === [3, 5], json_encode($r));

// The owner re-saving the same team tells nobody twice.
[, $r] = post("api/admin-content.php", $owner, ["id" => (int) $teamPost["id"], "title" => "Team post", "client" => "Acme Ltd",
    "member_ids_sent" => "1", "member_ids" => [5]], [], true);
check("page: an unchanged team is not told again", ($r["success"] ?? false) && notes($pdo, "admin", 5, "content_assigned") === 1, json_encode($r));

[$status, $r] = post("api/admin-content.php", $seo, ["id" => 301, "title" => "Beta launch", "client" => "Beta Co"], [], true);
check("page: an Admin can't edit a post they are not on", $status === 403, "HTTP $status " . json_encode($r));

// 302 is the super admin's scheduled post; the seo admin, assigned to it, fills it in.
[, $r] = post("api/admin-content.php", $seo, ["id" => 302, "title" => "Acme reel", "client" => "Acme Ltd", "caption" => "<p>Filled in</p>",
    "status" => "draft", "publish_now" => "1"], [], true);
$post = one($pdo, "SELECT status, post_date, publish_now, caption FROM content WHERE id = 302");
check("page: filling a post in keeps its schedule", ($r["success"] ?? false) && $post["status"] === "scheduled"
    && $post["post_date"] === day("+3") && (int) $post["publish_now"] === 0 && $post["caption"] === "<p>Filled in</p>", json_encode([$r, $post]));

// ── 2. api/v1 changes ───────────────────────────────────────────────────

[$status] = get("changes.php", $owner);
check("changes: POST only", $status === 405, "HTTP $status");
refusedChange("changes: an unknown change", $owner, "delete_everything", [], 404);
refusedChange("changes: arguments are checked", $sa, "create_form_draft", ["client" => 10, "title" => "", "questions" => []], 422);
refusedChange("changes: a checkbox needs options", $sa, "create_form_draft",
    ["client" => 10, "title" => "T", "questions" => [["text" => "Pick", "type" => "checkbox", "options" => ["One"]]]], 422);
refusedChange("changes: only your own clients", $seo, "create_form_draft",
    ["client" => 11, "title" => "T", "questions" => [["text" => "Q"]]], 404);

// A form draft: nothing until confirmed, then exactly once, through review.
$before = (int) $pdo->query("SELECT COUNT(*) FROM surveys")->fetchColumn();
[$status, $prep] = prepare($am, "create_form_draft", ["client" => 10, "title" => "AI onboarding", "description" => "Hi",
    "questions" => [["text" => "Your goals?", "type" => "textarea"], ["text" => "Channels", "type" => "checkbox", "options" => ["Instagram", "TikTok"]],
                    ["text" => "Logo", "type" => "file", "max_file_size_mb" => 5]]]);
check("changes: prepare writes nothing", $status === 200 && (int) $pdo->query("SELECT COUNT(*) FROM surveys")->fetchColumn() === $before, json_encode($prep));
check("changes: prepare says what will happen", strpos($prep["data"]["summary"] ?? "", "review queue") !== false
    && preg_match('/^wzct_[A-Za-z0-9_-]{43}$/', $prep["data"]["confirmation_token"] ?? "") === 1, json_encode($prep));
$fixtures["prepare_change"] = $prep["data"] ?? null;

[$status, $r] = confirm($am, $prep, [], "Something else entirely");
check("changes: the summary the person saw is required", $status === 422, "HTTP $status " . json_encode($r));
[$status, $r] = confirm($sa, $prep);
check("changes: someone else can't confirm it", $status === 404, "HTTP $status " . json_encode($r));

[$status, $done] = confirm($am, $prep);
$aiForm = one($pdo, "SELECT * FROM surveys WHERE title = 'AI onboarding'");
check("changes: a reviewer's AI form still waits for review", $status === 200 && $aiForm && $aiForm["status"] === "pending_review"
    && $done["data"]["result"]["form_id"] === (int) $aiForm["id"], json_encode($done));
check("changes: its questions", (int) one($pdo, "SELECT COUNT(*) AS n FROM survey_questions WHERE survey_id = ?", [$aiForm["id"]])["n"] === 3);
$fixtures["confirm_change"] = $done["data"] ?? null;

[$status, $again] = confirm($am, $prep);
check("changes: confirming twice changes nothing", $status === 200 && $again["data"]["duplicate"] === true
    && $again["data"]["result"] === $done["data"]["result"]
    && (int) $pdo->query("SELECT COUNT(*) FROM surveys")->fetchColumn() === $before + 1, json_encode($again));

[$status, $prep] = prepare($am, "save_my_client_note", ["client" => 10, "text" => "x"]);
$pdo->exec("UPDATE mcp_confirmations SET expires_at = " . (time() - 1) . " ORDER BY id DESC LIMIT 1");
[$status, $r] = confirm($am, $prep);
check("changes: an expired confirmation", $status === 410, "HTTP $status " . json_encode($r));

// Review, and a decision someone made in between stopping it.
refusedChange("changes: only reviewers review", $sa, "review_form", ["id" => (int) $aiForm["id"], "decision" => "approve"], 403);
refusedChange("changes: returning needs a comment", $am, "review_form", ["id" => (int) $aiForm["id"], "decision" => "return"], 422);
[, $prep] = prepare($am, "review_form", ["id" => (int) $aiForm["id"], "decision" => "approve"]);
post("api/admin-survey-review.php", $owner, ["surveyId" => (int) $aiForm["id"], "action" => "reject", "note" => "Owner says no"]);
[$status, $r] = confirm($am, $prep);
check("changes: re-checked at confirm - already decided", $status === 409
    && one($pdo, "SELECT status FROM surveys WHERE id = ?", [$aiForm["id"]])["status"] === "rejected", "HTTP $status " . json_encode($r));
check("changes: a stopped change isn't used up", one($pdo, "SELECT used_at FROM mcp_confirmations WHERE tool = 'review_form' ORDER BY id DESC LIMIT 1")["used_at"] === null);

change("changes: approve a form", $am, "review_form", ["id" => 100, "decision" => "approve"]);
check("changes: approval released it and told the client", one($pdo, "SELECT status FROM surveys WHERE id = 100")["status"] === "pending"
    && notes($pdo, "user", 10, "form_approved") === 3);
refusedChange("changes: a decided form can't be reviewed again", $am, "review_form", ["id" => 100, "decision" => "approve"], 409);

// Notes, inquiries, content.
change("changes: save my note", $am, "save_my_client_note", ["client" => 10, "text" => "Prefers mornings"]);
check("changes: the note is mine", getClientNote($pdo, 10, 3) === "Prefers mornings" && getClientNote($pdo, 10, 1) === "Owner's note");
refusedChange("changes: a super admin keeps no notes", $sa, "save_my_client_note", ["client" => 10, "text" => "x"], 403);

[, $done] = change("changes: an inquiry draft", $owner, "create_inquiry_draft",
    ["title" => "AI inquiry", "fields" => [["label" => "Name"], ["label" => "Needs", "type" => "textarea", "required" => false]]]);
check("changes: the inquiry is created closed", one($pdo, "SELECT status FROM inquiries WHERE title = 'AI inquiry'")["status"] === "inactive", json_encode($done));
refusedChange("changes: only the owner drafts inquiries", $am, "create_inquiry_draft", ["title" => "x", "fields" => [["label" => "a"]]], 403);

refusedChange("changes: an Admin can't draft content", $seo, "create_content_draft",
    ["client" => 10, "type" => "videos", "title" => "x"], 403);
change("changes: a super admin drafts content", $sa, "create_content_draft",
    ["client" => 10, "type" => "photos", "title" => "SA draft"]);
check("changes: the super admin's post is a draft", one($pdo, "SELECT status FROM content WHERE title = 'SA draft'")["status"] === "draft");
[, $done] = change("changes: a content draft", $am, "create_content_draft",
    ["client" => 10, "type" => "videos", "title" => "AI teaser", "caption" => "Line one\n\nLine <two>", "date" => $tomorrow, "time" => "10:15", "link" => "acme.example/x"]);
$aiPost = one($pdo, "SELECT * FROM content WHERE title = 'AI teaser'");
check("changes: the post is a draft, by its author, as the page saves it", $aiPost && $aiPost["status"] === "draft" && (int) $aiPost["created_by"] === 3
    && $aiPost["client"] === "Acme Ltd" && $aiPost["type_label"] === "Videos" && $aiPost["caption"] === "<p>Line one</p><p>Line &lt;two&gt;</p>"
    && $aiPost["post_time"] === "10:15:00" && $aiPost["link"] === "https://acme.example/x", json_encode($aiPost));
refusedChange("changes: content needs a company", $owner, "create_content_draft", ["client" => 12, "type" => "videos", "title" => "x"], 422);
refusedChange("changes: content types are the portal's", $owner, "create_content_draft", ["client" => 10, "type" => "tiktok", "title" => "x"], 422);

// Edits: forms, inquiries, content.
refusedChange("changes: a completed form can't be changed", $am, "update_form", ["id" => 102, "title" => "Too late"], 409);
refusedChange("changes: only forms you can see", $seo, "update_form", ["id" => 101, "title" => "Not mine"], 404);
refusedChange("changes: a form for a client no longer yours", $sa, "update_form", ["id" => 103, "title" => "Old Beta form v2"], 403);
refusedChange("changes: nothing to change on a form", $am, "update_form", ["id" => 100, "title" => "Brand questionnaire"], 422);
refusedChange("changes: edited questions are checked too", $am, "update_form",
    ["id" => 100, "questions" => [["text" => "Pick", "type" => "checkbox", "options" => ["One"]]]], 422);
$reviewersTold = notes($pdo, "admin", 1, "form_awaiting_review");
[$prep] = change("changes: edit a released form", $am, "update_form", ["id" => 100, "title" => "Brand questionnaire v2",
    "questions" => [["text" => "Your brand in a line?"], ["text" => "Tone", "type" => "checkbox", "options" => ["Playful", "Formal"]]]]);
check("changes: the summary says the client loses it until review", strpos($prep["data"]["summary"] ?? "", "taken back from the client") !== false
    && strpos($prep["data"]["summary"], "title \"Brand questionnaire\" → \"Brand questionnaire v2\"") !== false, $prep["data"]["summary"] ?? "");
$f100 = one($pdo, "SELECT title, status, reviewed_by_admin_id FROM surveys WHERE id = 100");
check("changes: an edited form goes back to review, even a reviewer's edit", $f100 == ["title" => "Brand questionnaire v2", "status" => "pending_review", "reviewed_by_admin_id" => null]
    && notes($pdo, "admin", 1, "form_awaiting_review") === $reviewersTold + 1, json_encode($f100));
$q100 = $pdo->query("SELECT question_text, chips FROM survey_questions WHERE survey_id = 100 ORDER BY sort_order")->fetchAll();
check("changes: the form's questions replaced", count($q100) === 2 && $q100[1]["chips"] === '["Playful","Formal"]', json_encode($q100));
change("changes: edit only a form's description", $am, "update_form", ["id" => 100, "description" => "Short and sweet"]);
check("changes: its questions left alone", one($pdo, "SELECT description FROM surveys WHERE id = 100")["description"] === "Short and sweet"
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM survey_questions WHERE survey_id = 100")["n"] === 2);

$aiInquiry = (int) one($pdo, "SELECT id FROM inquiries WHERE title = 'AI inquiry'")["id"];
refusedChange("changes: only the owner edits inquiries", $am, "update_inquiry", ["id" => $aiInquiry, "title" => "x"], 403);
refusedChange("changes: an answered inquiry's questions are locked", $owner, "update_inquiry", ["id" => 700, "title" => "New title"], 409);
refusedChange("changes: an inquiry is never opened from here", $owner, "update_inquiry", ["id" => 700, "close" => false], 422);
refusedChange("changes: nothing to change on an inquiry", $owner, "update_inquiry", ["id" => 700, "title" => "Free consult", "reference" => "PAGE-1"], 422);
[$prep] = change("changes: close an answered inquiry", $owner, "update_inquiry", ["id" => 700, "close" => true, "reference" => "Q4"]);
check("changes: closed, referenced, questions untouched", one($pdo, "SELECT status, reference, account_manager_admin_id FROM inquiries WHERE id = 700")
    == ["status" => "inactive", "reference" => "Q4", "account_manager_admin_id" => 3]
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM inquiry_fields WHERE inquiry_id = 700")["n"] === 2
    && strpos($prep["data"]["summary"] ?? "", "nobody can answer it") !== false, $prep["data"]["summary"] ?? "");
[$prep, $done] = change("changes: rename an inquiry and replace its questions", $owner, "update_inquiry", ["id" => $aiInquiry, "title" => "AI inquiry renamed",
    "fields" => [["label" => "Name"], ["label" => "Company"], ["label" => "Needs", "type" => "textarea"]]]);
check("changes: the summary warns the link moves", strpos($prep["data"]["summary"] ?? "", "inquiry.html?name=ai-inquiry-renamed") !== false
    && strpos($prep["data"]["summary"], "stop working") !== false, $prep["data"]["summary"] ?? "");
check("changes: renamed, still closed, three questions", ($done["data"]["result"]["name"] ?? "") === "ai-inquiry-renamed"
    && one($pdo, "SELECT slug, status FROM inquiries WHERE id = ?", [$aiInquiry]) == ["slug" => "ai-inquiry-renamed", "status" => "inactive"]
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM inquiry_fields WHERE inquiry_id = ?", [$aiInquiry])["n"] === 3, json_encode($done));

refusedChange("changes: a past scheduled post is live, not a draft", $owner, "update_content_draft", ["id" => 301, "title" => "x"], 409);
refusedChange("changes: a scheduled post isn't a draft", $sa, "update_content_draft", ["id" => 302, "title" => "x"], 409);
refusedChange("changes: only posts you can edit", $sa, "update_content_draft", ["id" => 300, "title" => "x"], 404);
refusedChange("changes: a time needs a date when editing too", $owner, "update_content_draft", ["id" => 300, "date" => ""], 422);
refusedChange("changes: nothing to change on a post", $am, "update_content_draft", ["id" => (int) $aiPost["id"], "title" => "AI teaser"], 422);
[$prep] = change("changes: edit a draft post", $am, "update_content_draft", ["id" => (int) $aiPost["id"], "type" => "photos",
    "caption" => "New caption", "time" => "", "link" => ""]);
$edited = one($pdo, "SELECT title, status, content_type, type_label, caption, post_date, post_time, link FROM content WHERE id = ?", [$aiPost["id"]]);
check("changes: only the given fields changed, and it is still a draft", $edited == ["title" => "AI teaser", "status" => "draft",
    "content_type" => "photos", "type_label" => "Photos", "caption" => "<p>New caption</p>", "post_date" => $tomorrow, "post_time" => null, "link" => null]
    && strpos($prep["data"]["summary"] ?? "", "It stays a draft") !== false, json_encode($edited));

// Images by link: checked on prepare, never fetched from the server's own network.
$draft = ["client" => 10, "type" => "photos", "title" => "x"];
refusedChange("changes: image links are https", $owner, "create_content_draft", $draft + ["images" => ["http://example.com/a.png"]], 422);
refusedChange("changes: no loopback image links", $owner, "create_content_draft", $draft + ["images" => ["https://127.0.0.1/a.png"]], 422);
refusedChange("changes: no private-network image links", $owner, "create_content_draft", $draft + ["images" => ["https://10.0.0.5/a.png"]], 422);
refusedChange("changes: no localhost image links", $owner, "create_content_draft", $draft + ["images" => ["https://localhost/a.png"]], 422);
refusedChange("changes: no image links with a password", $owner, "create_content_draft", $draft + ["images" => ["https://u:p@8.8.8.8/a.png"]], 422);
refusedChange("changes: images is a list", $owner, "create_content_draft", $draft + ["images" => "https://8.8.8.8/a.png"], 422);
refusedChange("changes: at most five images", $owner, "create_content_draft", $draft + ["images" => array_map(function ($i) {
    return "https://8.8.8.8/$i.png";
}, range(1, 6))], 422);
[$status, $prep] = prepare($owner, "create_content_draft", $draft + ["images" => ["https://8.8.8.8/a.png", "https://8.8.8.8/b.png"]]);
check("changes: image links are only checked on prepare", $status === 200
    && strpos($prep["data"]["summary"] ?? "", "with 2 images") !== false, "HTTP $status " . json_encode($prep));
refusedChange("changes: update image links are checked", $am, "update_content_draft", ["id" => (int) $aiPost["id"], "images" => ["https://192.168.1.4/a.png"]], 422);
refusedChange("changes: no images to remove is nothing to change", $am, "update_content_draft", ["id" => (int) $aiPost["id"], "images" => []], 422);
[$status, $prep] = prepare($am, "update_content_draft", ["id" => (int) $aiPost["id"], "images" => ["https://8.8.8.8/a.png"]]);
check("changes: adding images is in the summary", $status === 200
    && strpos($prep["data"]["summary"] ?? "", "1 image downloaded from the links given") !== false, "HTTP $status " . json_encode($prep));

// The team on a post: its client's team (Acme: Sam, Amy, Sean - not Andy).
$aiId = (int) $aiPost["id"];
[$status, $body] = get("content.php?teammates=1&id=$aiId", $am);
$mates = array_column($body["data"]["items"] ?? [], null, "id");
check("changes: a post's teammates are its client's team", $status === 200 && array_keys($mates) == [3, 2, 5]
    && $mates[3]["is_you"] === true && $mates[2]["on_post"] === false && ($body["data"]["client"] ?? null) === "Acme Ltd", "HTTP $status " . json_encode($body));
[$status, $body] = get("content.php?teammates=1&client=11", $owner);
check("changes: a new post's teammates by client", $status === 200 && array_column($body["data"]["items"] ?? [], "id") === [4], "HTTP $status " . json_encode($body));
refusedChange("changes: only the client's team goes on a post", $am, "update_content_draft", ["id" => $aiId, "team" => [4]], 422);
refusedChange("changes: not yourself on the team", $am, "update_content_draft", ["id" => $aiId, "team" => [3]], 422);
refusedChange("changes: no deactivated teammates", $owner, "update_content_draft", ["id" => $aiId, "team" => [6]], 422);
refusedChange("changes: orientation is the page's", $am, "update_content_draft", ["id" => $aiId, "orientation" => "square"], 422);
refusedChange("changes: priority is the page's", $am, "update_content_draft", ["id" => $aiId, "priority" => "urgent"], 422);

$samTold = notes($pdo, "admin", 2, "content_assigned");
$seanTold = notes($pdo, "admin", 5, "content_assigned");
[$prep] = change("changes: a draft's team, notes, priority and orientation", $am, "update_content_draft", ["id" => $aiId,
    "orientation" => "vertical", "priority" => "high", "notes" => "Art Director: check the brand.
SEO: no ranking promises.", "team" => [2, 5]]);
$teamPost = one($pdo, "SELECT orientation, priority, notes, status FROM content WHERE id = ?", [$aiId]);
check("changes: the team fields saved, still a draft", $teamPost == ["orientation" => "vertical", "priority" => "high",
    "notes" => "Art Director: check the brand.
SEO: no ranking promises.", "status" => "draft"]
    && teamOf($pdo, $aiId) === [2, 5], json_encode($teamPost));
check("changes: each new teammate is notified", notes($pdo, "admin", 2, "content_assigned") === $samTold + 1
    && notes($pdo, "admin", 5, "content_assigned") === $seanTold + 1
    && strpos($prep["data"]["summary"] ?? "", "team \"(nobody)\" → \"Sam, Sean\"") !== false
    && strpos($prep["data"]["summary"], "are notified") !== false, $prep["data"]["summary"] ?? "");
change("changes: a teammate taken off", $am, "update_content_draft", ["id" => $aiId, "team" => [5]]);
check("changes: only Sean left, and nobody told again", teamOf($pdo, $aiId) === [5]
    && notes($pdo, "admin", 5, "content_assigned") === $seanTold + 1);
change("changes: Sam back on the post", $am, "update_content_draft", ["id" => $aiId, "team" => [2, 5]]);
refusedChange("changes: an assignee doesn't change the team", $sa, "update_content_draft", ["id" => $aiId, "team" => []], 403);
change("changes: an assignee fills a post in", $sa, "update_content_draft", ["id" => $aiId, "notes" => ""]);
check("changes: notes cleared", one($pdo, "SELECT notes FROM content WHERE id = ?", [$aiId])["notes"] === null);

[$status, $body] = get("content.php?id=$aiId", $am);
check("changes: get_content shows priority, team and notes", $status === 200 && ($body["data"]["priority"] ?? null) === "high"
    && ($body["data"]["orientation"] ?? null) === "vertical"
    && array_column($body["data"]["assignees"] ?? [], "name") === ["Sam", "Sean"]
    && array_key_exists("notes", $body["data"]["untrusted_content"] ?? []), "HTTP $status " . json_encode($body));

[, $done] = change("changes: a draft with its team", $am, "create_content_draft", ["client" => 10, "type" => "photos", "title" => "Team draft",
    "orientation" => "vertical", "priority" => "low", "notes" => "Brief", "team" => [5]]);
$teamDraft = one($pdo, "SELECT id, orientation, priority, notes FROM content WHERE title = 'Team draft'");
check("changes: a new draft carries its team", $teamDraft["orientation"] === "vertical" && $teamDraft["priority"] === "low"
    && $teamDraft["notes"] === "Brief" && teamOf($pdo, $teamDraft["id"]) === [5]
    && ($done["data"]["result"]["team"] ?? null) === 1, json_encode([$teamDraft, $done]));
refusedChange("changes: a new draft's team is its client's", $am, "create_content_draft", ["client" => 10, "type" => "photos", "title" => "x", "team" => [4]], 422);

// Scheduling and publishing: the owner and account managers, drafts only.
refusedChange("changes: a super admin doesn't publish", $sa, "publish_content", ["id" => $aiId, "publish_now" => true], 403);
refusedChange("changes: a schedule needs a date and time", $am, "publish_content", ["id" => $aiId, "date" => $tomorrow], 422);
refusedChange("changes: now or a time, not both", $am, "publish_content", ["id" => $aiId, "publish_now" => true, "date" => $tomorrow, "time" => "09:00"], 422);
$acmeTold = notes($pdo, "user", 10, "content_published");
[$prep] = change("changes: schedule a draft", $am, "publish_content", ["id" => $aiId, "date" => $tomorrow, "time" => "09:00"]);
$scheduled = one($pdo, "SELECT status, publish_now, post_date, post_time FROM content WHERE id = ?", [$aiId]);
check("changes: scheduled, and nobody told yet", $scheduled == ["status" => "scheduled", "publish_now" => 0, "post_date" => $tomorrow, "post_time" => "09:00:00"]
    && notes($pdo, "user", 10, "content_published") === $acmeTold
    && strpos($prep["data"]["summary"] ?? "", "to go live on $tomorrow at 09:00") !== false, json_encode([$scheduled, $prep["data"]["summary"] ?? ""]));
refusedChange("changes: a scheduled post isn't scheduled again", $am, "publish_content", ["id" => $aiId, "publish_now" => true], 409);
[$prep] = change("changes: a past time goes live now", $owner, "publish_content", ["id" => (int) $teamDraft["id"], "date" => day("-1"), "time" => "09:00"]);
$live = one($pdo, "SELECT status, post_date FROM content WHERE id = ?", [$teamDraft["id"]]);
check("changes: published, keeping its date, and the client told", $live == ["status" => "published", "post_date" => day("-1")]
    && notes($pdo, "user", 10, "content_published") === $acmeTold + 1
    && strpos($prep["data"]["summary"] ?? "", "already passed") !== false, json_encode([$live, $prep["data"]["summary"] ?? ""]));
change("changes: publish a draft now", $am, "publish_content", ["id" => (int) one($pdo, "SELECT id FROM content WHERE title = 'SA draft'")["id"], "publish_now" => true]);
check("changes: published now, with no date", one($pdo, "SELECT status, publish_now, post_date FROM content WHERE title = 'SA draft'")
    == ["status" => "published", "publish_now" => 1, "post_date" => null]
    && notes($pdo, "user", 10, "content_published") === $acmeTold + 2);

// Meetings.
refusedChange("changes: no calendar for an seo admin", $seo, "propose_meeting", ["client" => 10, "date" => $tomorrow, "time" => "10:00", "topic" => "x"], 403);
refusedChange("changes: not in the past", $am, "propose_meeting", ["client" => 10, "date" => day("-1"), "time" => "10:00", "topic" => "x"], 422);
[, $done] = change("changes: propose a meeting", $am, "propose_meeting", ["client" => 10, "date" => $tomorrow, "time" => "11:00", "topic" => "AI meeting"]);
check("changes: the meeting is the page's", one($pdo, "SELECT COUNT(*) AS n FROM appointments WHERE topic = 'AI meeting' AND requested_by = 'admin' AND admin_id = 3 AND status = 'pending'")["n"] == 1
    && notes($pdo, "user", 10, "appointment_request") === 2, json_encode($done));

change("changes: accept a client's request", $am, "respond_to_meeting", ["id" => 400, "decision" => "accept"]);
check("changes: accepted and told", one($pdo, "SELECT status, admin_id FROM appointments WHERE id = 400") == ["status" => "approved", "admin_id" => 3]
    && notes($pdo, "user", 10, "appointment_answered") === 1);
refusedChange("changes: already answered", $am, "respond_to_meeting", ["id" => 400, "decision" => "decline"], 409);
refusedChange("changes: W|ZONE's own request isn't ours to answer", $owner, "respond_to_meeting", ["id" => 401, "decision" => "accept"], 404);

// Projects.
refusedChange("changes: not your project", $am, "post_project_update", ["project" => 501, "title" => "x"], 404);
[, $done] = change("changes: post an update", $am2, "post_project_update", ["project" => 501, "title" => "AI update", "body" => "All good."]);
check("changes: published and the client told once", ($done["data"]["result"]["client_notified"] ?? null) === true
    && notes($pdo, "user", 11, "project_update") === 2, json_encode($done));
[, $done] = change("changes: a future update", $am2, "post_project_update", ["project" => 501, "title" => "AI later", "date" => day("+3")]);
check("changes: not live yet, not announced", ($done["data"]["result"]["client_notified"] ?? null) === false, json_encode($done));

refusedChange("changes: a member can't edit a teammate's task", $seo, "update_task", ["id" => 601, "title" => "Mine now"], 403);
refusedChange("changes: nothing to change", $am2, "update_task", ["id" => 600, "status" => "draft"], 422);
refusedChange("changes: completion is never set by hand", $am2, "update_task", ["id" => 600, "is_complete" => true], 422);
[$prep, $done] = change("changes: publish a draft task", $am2, "update_task", ["id" => 600, "status" => "published"]);
check("changes: the preview lists the change", strpos($prep["data"]["summary"], "status \"draft\" → \"published\"") !== false, $prep["data"]["summary"] ?? "");
$t600 = one($pdo, "SELECT status, is_complete, title FROM project_tasks WHERE id = 600");
check("changes: only what was asked changed, and the client was told", $t600 == ["status" => "published", "is_complete" => 0, "title" => "Andy's draft"]
    && ($done["data"]["result"]["client_notified"] ?? null) === true, json_encode([$t600, $done]));

// Notifications - no confirmation, own inbox only.
[$status, $r] = post("api/v1/notifications.php", $sa, ["ids" => [901, 902]]);
check("mark read: only my own", $status === 200 && $r["data"]["marked"] === 1
    && $r["data"]["unread_count"] === (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE recipient_kind = 'admin' AND recipient_id = 2 AND read_at IS NULL")["n"]
    && one($pdo, "SELECT read_at FROM notifications WHERE id = 901")["read_at"] !== null
    && one($pdo, "SELECT read_at FROM notifications WHERE id = 902")["read_at"] === null, json_encode($r));
$fixtures["mark_notifications_read"] = $r["data"] ?? null;
[$status, $r] = post("api/v1/notifications.php", $sa, ["ids" => []]);
check("mark read: ids or all", $status === 422, "HTTP $status");
[$status, $r] = post("api/v1/notifications.php", $sa, ["all" => true]);
check("mark read: all", $status === 200 && $r["data"]["unread_count"] === 0, json_encode($r));

// Through an MCP connection: only what it was granted, and only through it.
[$status, $r] = prepare($mcpToken, "create_form_draft", ["client" => 10, "title" => "x", "questions" => [["text" => "q"]]], ["X-MCP-Key" => $mcpKey]);
check("mcp: a write scope that wasn't granted", $status === 403 && $r["error"]["code"] === "insufficient_scope", "HTTP $status " . json_encode($r));

$writeToken = "wzat_" . str_repeat("w", 43);
seed($pdo, "mcp_grants", [["id" => 3, "client_id" => "c1", "principal_kind" => "admin", "principal_id" => 2,
    "scopes" => "clients:read forms:read forms:write", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($writeToken), "kind" => "access", "grant_id" => 3, "expires_at" => time() + 3600]]);
$mcp = ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "create_form_draft"];
[$status, $prep] = prepare($writeToken, "create_form_draft", ["client" => 10, "title" => "Via MCP", "questions" => [["text" => "q"]]], $mcp);
[$status, $r] = confirm($sa, $prep);
check("mcp: a connection's confirmation can't be used from a browser session", $status === 404, "HTTP $status " . json_encode($r));
[$status, $r] = confirm($writeToken, $prep, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "confirm_change"]);
check("mcp: confirmed through the connection that prepared it", $status === 200
    && one($pdo, "SELECT grant_id FROM mcp_confirmations WHERE tool = 'create_form_draft' ORDER BY id DESC LIMIT 1")["grant_id"] == 3
    && one($pdo, "SELECT status FROM surveys WHERE title = 'Via MCP'")["status"] === "pending_review", "HTTP $status " . json_encode($r));
check("mcp: both steps audited", (int) one($pdo, "SELECT COUNT(*) AS n FROM mcp_audit_log WHERE grant_id = 3 AND endpoint = '/api/v1/changes.php'")["n"] === 2);
