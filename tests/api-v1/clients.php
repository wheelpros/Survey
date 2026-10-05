<?php

/*
| Clients - required by run.php after writes.php, sharing its scope.
|
| A client's own AI connection (api/v1/me and the client changes): only with
| the owner's switch on, only ever the client's own records, nothing a
| client page doesn't show them, and the same results as the pages for
| what they change. Plus the browser endpoints whose client writes moved
| into shared functions (submit-survey.php, calendar.php's client actions).
*/

$alice = "tok-client";
$bob = "tok-client2";
$pdo->exec("UPDATE users SET session_token = 'tok-client2' WHERE id = 11");

seed($pdo, "surveys", [
    ["id" => 120, "title" => "Alice's brief", "assigned_user_id" => 10, "status" => "pending", "created_by_admin_id" => 3],
    ["id" => 121, "title" => "Alice's logo", "assigned_user_id" => 10, "status" => "pending", "created_by_admin_id" => 3],
    ["id" => 122, "title" => "Alice's unreleased", "assigned_user_id" => 10, "status" => "pending_review", "created_by_admin_id" => 2],
    ["id" => 123, "title" => "Bob's brief", "assigned_user_id" => 11, "status" => "pending", "created_by_admin_id" => 4],
]);
seed($pdo, "survey_questions", [
    ["id" => 1200, "survey_id" => 120, "question_text" => "Company name", "question_type" => "input", "sort_order" => 1, "chips" => "[]"],
    ["id" => 1201, "survey_id" => 120, "question_text" => "Goals", "question_type" => "textarea", "sort_order" => 2, "chips" => "[]"],
    ["id" => 1202, "survey_id" => 120, "question_text" => "Channels", "question_type" => "checkbox", "sort_order" => 3, "chips" => '["Instagram","TikTok","LinkedIn"]'],
    ["id" => 1203, "survey_id" => 120, "question_text" => "I agree", "question_type" => "checkbox", "sort_order" => 4, "chips" => "[]"],
    ["id" => 1210, "survey_id" => 121, "question_text" => "Logo file", "question_type" => "file", "sort_order" => 1, "chips" => "[]"],
    ["id" => 1220, "survey_id" => 122, "question_text" => "Secret", "question_type" => "input", "sort_order" => 1, "chips" => "[]"],
    ["id" => 1230, "survey_id" => 123, "question_text" => "Bob's question", "question_type" => "input", "sort_order" => 1, "chips" => "[]"],
]);
seed($pdo, "content", [
    ["id" => 310, "title" => "For everyone", "client" => null, "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 3],
]);
seed($pdo, "project_tasks", [
    ["id" => 610, "project_id" => 500, "title" => "Live update", "status" => "published", "scheduled_date" => day("-1"), "created_by_admin_id" => 3],
    ["id" => 611, "project_id" => 500, "title" => "Draft update", "status" => "draft", "created_by_admin_id" => 3],
    ["id" => 612, "project_id" => 500, "title" => "Future update", "status" => "published", "scheduled_date" => day("+5"), "created_by_admin_id" => 3],
]);
seed($pdo, "appointments", [
    ["id" => 420, "user_id" => 10, "title" => "Declined", "topic" => "Declined", "date" => day("+3"), "time" => "10:00:00", "status" => "rejected", "requested_by" => "admin", "admin_id" => 3],
    ["id" => 421, "user_id" => 10, "title" => "Strategy", "topic" => "Strategy", "date" => day("+4"), "time" => "14:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 3],
    ["id" => 422, "user_id" => 11, "title" => "Bob's", "topic" => "Bob's", "date" => day("+4"), "time" => "15:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 4],
]);
seed($pdo, "notifications", [
    ["id" => 950, "recipient_kind" => "user", "recipient_id" => 10, "event_type" => "x", "title" => "For Alice"],
    ["id" => 951, "recipient_kind" => "user", "recipient_id" => 11, "event_type" => "x", "title" => "For Bob"],
]);

// ── The owner's switch ───────────────────────────────────────────────────

[$status, $r] = get("me/overview.php", $alice);
check("clients: nothing while the owner hasn't allowed client connections", $status === 403
    && $r["error"]["code"] === "insufficient_scope", "HTTP $status " . json_encode($r));

setSiteSetting($pdo, "mcp_clients_enabled", "1");

// ── Reading: only their own ──────────────────────────────────────────────

[$status, $ov] = get("me/overview.php", $alice);
$ov = $ov["data"] ?? [];
check("me: overview", $status === 200 && $ov["me"]["company_name"] === "Acme Ltd", "HTTP $status " . json_encode($ov));
check("me: a meeting W|ZONE asked for is waiting on me", in_array(421, array_column($ov["meetings_waiting_on_you"], "id"), true)
    && !in_array(422, array_column($ov["meetings_waiting_on_you"], "id"), true), json_encode($ov["meetings_waiting_on_you"]));
$toFill = array_column($ov["forms_to_fill_in"], "id");
check("me: forms to fill in - released ones only", in_array(120, $toFill, true) && !in_array(122, $toFill, true) && !in_array(123, $toFill, true), json_encode($toFill));
check("me: projects", array_column($ov["active_projects"], "id") === [500], json_encode($ov["active_projects"]));
check("me: no staff names on projects", !array_key_exists("account_manager_name", $ov["active_projects"][0] ?? []));

refused("me: an unreleased form doesn't exist", "me/forms.php?id=122", $alice, 404);
refused("me: another client's form doesn't exist", "me/forms.php?id=123", $alice, 404);
[, $f] = get("me/forms.php?id=120", $alice);
check("me: a form's questions and options", ($f["data"]["questions"][2]["options"] ?? null) === ["Instagram", "TikTok", "LinkedIn"]
    && $f["data"]["my_answers"] === null, json_encode($f));
[, $f] = get("me/forms.php?id=102", $alice);
check("me: a sent form comes back with my answers", count($f["data"]["my_answers"]["untrusted_content"]["answers"] ?? []) === 3, json_encode($f));
[, $list] = get("me/forms.php", $alice);
check("me: my form list leaves out what isn't released", !in_array(122, ids($list), true) && !in_array(123, ids($list), true) && in_array(120, ids($list), true));

[, $c] = get("me/content.php", $alice);
$cIds = ids($c);
check("me: live posts for my company and for everyone", in_array(303, $cIds, true) && in_array(310, $cIds, true)
    && !in_array(300, $cIds, true) && !in_array(301, $cIds, true) && !in_array(302, $cIds, true), json_encode($cIds));
[, $c] = get("me/content.php", $bob);
check("me: another client sees theirs, not mine", in_array(301, ids($c), true) && in_array(310, ids($c), true) && !in_array(303, ids($c), true), json_encode(ids($c)));
refused("me: a draft post doesn't exist", "me/content.php?id=300", $alice, 404);

sees("me: my projects only", "me/projects.php", $alice, [500]);
refused("me: another client's project", "me/projects.php?id=501", $alice, 404);
[, $pr] = get("me/projects.php?id=500", $alice);
check("me: only live updates - no drafts, nothing dated ahead", array_column($pr["data"]["updates"] ?? [], "id") === [610], json_encode($pr));

[, $cal] = get("me/calendar.php?from=" . day("-1") . "&to=" . day("+10"), $alice);
$mIds = ids($cal, "meetings");
check("me: calendar - a request I declined drops off, other people's never appear", in_array(421, $mIds, true)
    && !in_array(420, $mIds, true) && !in_array(422, $mIds, true), json_encode($mIds));
refused("me: another client's meeting", "me/calendar.php?id=422", $alice, 404);

sees("me: my notifications only", "me/notifications.php?unread=1", $alice, array_map("intval",
    $pdo->query("SELECT id FROM notifications WHERE recipient_kind = 'user' AND recipient_id = 10 AND read_at IS NULL")->fetchAll(PDO::FETCH_COLUMN)));
[$status, $r] = post("api/v1/me/notifications.php", $alice, ["ids" => [950, 951]]);
check("me: marking read touches only my own", $status === 200 && $r["data"]["marked"] === 1
    && one($pdo, "SELECT read_at FROM notifications WHERE id = 951")["read_at"] === null, json_encode($r));

// ── The walls between staff and clients ──────────────────────────────────

refused("walls: a client on a staff area", "forms.php", $alice, 403);
refused("walls: staff on a client's view", "me/overview.php", $owner, 403);
refusedChange("walls: a client can't make a staff change", $alice, "create_form_draft", ["client" => 10, "title" => "x", "questions" => [["text" => "q"]]], 404);
refusedChange("walls: staff can't make a client's change", $owner, "request_meeting", ["date" => $tomorrow, "time" => "10:00", "topic" => "x"], 404);

// ── Changes ──────────────────────────────────────────────────────────────

refusedChange("me: a meeting in the past", $alice, "request_meeting", ["date" => day("-1"), "time" => "10:00", "topic" => "x"], 422);
$before = notes($pdo, "admin", 1, "appointment_request");
[, $done] = change("me: request a meeting", $alice, "request_meeting", ["date" => $tomorrow, "time" => "16:00", "topic" => "AI client meeting"]);
$m = one($pdo, "SELECT user_id, requested_by, status, client FROM appointments WHERE id = ?", [$done["data"]["result"]["meeting_id"] ?? 0]);
check("me: the request is the dashboard's", $m == ["user_id" => 10, "requested_by" => "user", "status" => "pending", "client" => "Acme Ltd"]
    && notes($pdo, "admin", 1, "appointment_request") === $before + 1 && notes($pdo, "admin", 3, "appointment_request") >= 1, json_encode($m));

refusedChange("me: not someone else's meeting", $bob, "respond_to_my_meeting", ["id" => 421, "decision" => "accept"], 404);
refusedChange("me: not a meeting I asked for", $alice, "respond_to_my_meeting", ["id" => $done["data"]["result"]["meeting_id"] ?? 0, "decision" => "accept"], 404);
$before = notes($pdo, "admin", 3, "appointment_answered");
change("me: accept W|ZONE's request", $alice, "respond_to_my_meeting", ["id" => 421, "decision" => "accept"]);
check("me: accepted, and the admin who asked is told", one($pdo, "SELECT status FROM appointments WHERE id = 421")["status"] === "approved"
    && notes($pdo, "admin", 3, "appointment_answered") === $before + 1);
refusedChange("me: answered already", $alice, "respond_to_my_meeting", ["id" => 421, "decision" => "decline"], 409);

refusedChange("me: an unreleased form can't be sent", $alice, "submit_my_form", ["id" => 122, "answers" => [["question_id" => 1220, "value" => "x"]]], 404);
[$status, $r] = prepare($alice, "submit_my_form", ["id" => 121, "answers" => [["question_id" => 1210, "value" => "logo.png"]]]);
check("me: a form needing a file is sent from the portal", $status === 422 && strpos($r["error"]["message"], "survey.html?id=121") !== false, json_encode($r));
[$status, $r] = prepare($alice, "submit_my_form", ["id" => 120, "answers" => [["question_id" => 1200, "value" => "Acme"]]]);
check("me: every question needs an answer", $status === 422 && strpos($r["error"]["message"], "Goals") !== false
    && strpos($r["error"]["message"], "I agree") !== false, json_encode($r));
refusedChange("me: ticked options come from the list", $alice, "submit_my_form", ["id" => 120, "answers" => [
    ["question_id" => 1200, "value" => "Acme"], ["question_id" => 1201, "value" => "Grow"],
    ["question_id" => 1202, "value" => ["Myspace"]], ["question_id" => 1203, "value" => true]]], 422);
refusedChange("me: not a question on this form", $alice, "submit_my_form", ["id" => 120, "answers" => [["question_id" => 1230, "value" => "x"]]], 422);

$before = notes($pdo, "admin", 3, "form_submitted");
[$prep, $done] = change("me: send a form", $alice, "submit_my_form", ["id" => 120, "answers" => [
    ["question_id" => 1200, "value" => "Acme Ltd"], ["question_id" => 1201, "value" => "Grow online"],
    ["question_id" => 1202, "value" => ["TikTok", "Instagram"]], ["question_id" => 1203, "value" => true]]]);
$responseId = $done["data"]["result"]["response_id"] ?? 0;
$stored = $pdo->query("SELECT question_id, question_label, answer FROM survey_answers WHERE response_id = " . (int) $responseId . " ORDER BY id")->fetchAll();
check("me: answers stored as the page stores them", one($pdo, "SELECT status FROM surveys WHERE id = 120")["status"] === "completed"
    && $stored[2]["answer"] === "✅ Instagram, ✅ TikTok" && $stored[3]["answer"] === "✅" && $stored[0]["question_label"] === "Company name",
    json_encode($stored));
check("me: the client's admins are told", notes($pdo, "admin", 3, "form_submitted") === $before + 1);
refusedChange("me: sent already", $alice, "submit_my_form", ["id" => 120, "answers" => [["question_id" => 1200, "value" => "x"]]], 409);

// ── The pages, on the shared code ────────────────────────────────────────

[, $r] = post("api/submit-survey.php", $alice, ["surveyId" => 122, "answers" => json_encode([["questionId" => 1220, "questionLabel" => "Secret", "answer" => "x"]])], [], true);
check("page: an unreleased form can no longer be submitted", ($r["message"] ?? "") === "Survey not found"
    && one($pdo, "SELECT status FROM surveys WHERE id = 122")["status"] === "pending_review", json_encode($r));
[, $r] = post("api/submit-survey.php", $bob, ["surveyId" => 123, "answers" => json_encode([["questionId" => 1230, "questionLabel" => "Bob's question", "answer" => "Yes"]])], [], true);
check("page: submitting works as before", ($r["message"] ?? "") === "Survey submitted successfully"
    && one($pdo, "SELECT status FROM surveys WHERE id = 123")["status"] === "completed", json_encode($r));
[, $r] = post("api/submit-survey.php", $alice, ["surveyId" => 121, "answers" => json_encode([["questionId" => 1210, "questionLabel" => "Logo file", "answer" => ""]])], [], true);
check("page: a file question still needs its file", ($r["message"] ?? "") === "File is required for: Logo file"
    && one($pdo, "SELECT COUNT(*) AS n FROM survey_responses WHERE survey_id = 121")["n"] == 0, json_encode($r));

[, $r] = post("api/calendar.php?action=create_user_request", $bob, ["date" => $tomorrow, "time" => "12:00", "topic" => "Bob asks"]);
check("page: a client's request", ($r["message"] ?? "") === "Request sent to the admin team."
    && one($pdo, "SELECT requested_by FROM appointments WHERE topic = 'Bob asks'")["requested_by"] === "user", json_encode($r));
[, $r] = post("api/calendar.php?action=respond_appointment", $bob, ["id" => 422, "status" => "rejected"]);
check("page: a client declining", ($r["message"] ?? "") === "Meeting declined" && one($pdo, "SELECT status FROM appointments WHERE id = 422")["status"] === "rejected", json_encode($r));
[, $r] = post("api/calendar.php?action=respond_appointment", $bob, ["id" => 421, "status" => "approved"]);
check("page: a client can't answer someone else's meeting", ($r["success"] ?? true) === false
    && one($pdo, "SELECT status FROM appointments WHERE id = 421")["status"] === "approved", json_encode($r));

// ── Through an MCP connection, and the switch going off ──────────────────

$clientToken = "wzat_" . str_repeat("c", 43);
seed($pdo, "mcp_grants", [["id" => 4, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 10,
    "scopes" => "self:read self:write", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($clientToken), "kind" => "access", "grant_id" => 4, "expires_at" => time() + 3600]]);
[$status, $r] = get("me/overview.php", $clientToken, ["X-MCP-Key" => $mcpKey, "X-MCP-Tool" => "my_overview"]);
check("mcp: a client's connection", $status === 200 && $r["data"]["me"]["name"] === "Alice", "HTTP $status");
$fixtures["my_overview"] = $r["data"] ?? null;

setSiteSetting($pdo, "mcp_clients_enabled", "0");
[$status, $r] = get("me/overview.php", $clientToken, ["X-MCP-Key" => $mcpKey]);
check("mcp: switching client access off cuts existing connections at once", $status === 403, "HTTP $status " . json_encode($r));
setSiteSetting($pdo, "mcp_clients_enabled", "1");

if (in_array("--fixtures", $argv, true)) {
    foreach ([
        "my_calendar" => "me/calendar.php?from=" . day("-1") . "&to=" . day("+10"),
        "get_my_meeting" => "me/calendar.php?id=421",
        "my_forms" => "me/forms.php",
        "get_my_form" => "me/forms.php?id=102",
        "my_content" => "me/content.php",
        "my_projects" => "me/projects.php",
        "get_my_project" => "me/projects.php?id=500",
        "my_notifications" => "me/notifications.php",
    ] as $tool => $path) {
        [$status, $body] = get($path, $alice);
        check("fixture $tool", $status === 200, "HTTP $status " . json_encode($body));
        $fixtures[$tool] = $body["data"] ?? null;
    }
    [, $r] = post("api/v1/me/notifications.php", $alice, ["all" => true]);
    $fixtures["mark_my_notifications_read"] = $r["data"] ?? null;
}
