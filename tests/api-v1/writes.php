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
check("page: only the owner creates inquiries", ($r["message"] ?? "") === "Only the owner can create inquiries", json_encode($r));

[, $r] = post("api/admin-content.php", $seo, ["title" => "Page post", "client" => "Acme Ltd", "content_type" => "videos", "link" => "acme.example", "status" => "published"], [], true);
$post = one($pdo, "SELECT status, link FROM content WHERE title = 'Page post'");
check("page: content links get https, and a non-publisher's post stays a draft",
    $post && $post["link"] === "https://acme.example" && $post["status"] === "draft", json_encode([$r, $post]));

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

[, $done] = change("changes: a content draft", $seo, "create_content_draft",
    ["client" => 10, "type" => "videos", "title" => "AI teaser", "caption" => "Line one\n\nLine <two>", "date" => $tomorrow, "time" => "10:15", "link" => "acme.example/x"]);
$aiPost = one($pdo, "SELECT * FROM content WHERE title = 'AI teaser'");
check("changes: the post is a draft, by its author, as the page saves it", $aiPost && $aiPost["status"] === "draft" && (int) $aiPost["created_by"] === 5
    && $aiPost["client"] === "Acme Ltd" && $aiPost["type_label"] === "Videos" && $aiPost["caption"] === "<p>Line one</p><p>Line &lt;two&gt;</p>"
    && $aiPost["post_time"] === "10:15:00" && $aiPost["link"] === "https://acme.example/x", json_encode($aiPost));
refusedChange("changes: content needs a company", $owner, "create_content_draft", ["client" => 12, "type" => "videos", "title" => "x"], 422);
refusedChange("changes: content types are the portal's", $owner, "create_content_draft", ["client" => 10, "type" => "tiktok", "title" => "x"], 422);

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
[$prep, $done] = change("changes: publish a draft task", $am2, "update_task", ["id" => 600, "status" => "published", "is_complete" => true]);
check("changes: the preview lists the change", strpos($prep["data"]["summary"], "status \"draft\" → \"published\"") !== false, $prep["data"]["summary"] ?? "");
$t600 = one($pdo, "SELECT status, is_complete, title FROM project_tasks WHERE id = 600");
check("changes: only what was asked changed, and the client was told", $t600 == ["status" => "published", "is_complete" => 1, "title" => "Andy's draft"]
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
