<?php

/*
| Team forms - required by run.php after announcements.php, sharing its scope.
|
|   1. Sending: only with team_forms.send (the Director by default), to the
|      whole team, to roles, to people, or roles plus people - never the
|      sender, never a switched-off admin.
|   2. Seeing: a recipient sees the questions and their own answers; the
|      sender and the Director see everyone's; anyone else gets a 404.
|   3. Answering: once, while it's open; closing stops it.
|   4. The same through api/v1, and the permission handed to a role.
*/

require_once $root . "/api/team-form-writes.php";
ensureTeamFormTables($pdo);

$tfQuestions = [
    ["text" => "How was this quarter?", "type" => "textarea"],
    ["text" => "Which tools do you use?", "type" => "checkbox", "chips" => "Figma, Notion, Slack"],
    ["text" => "Anything else?", "type" => "input"],
];

/** Active admin ids, lowest first - optionally only some roles. Earlier test files add admins of their own. */
function activeAdmins(PDO $pdo, array $roles = [])
{
    $sql = "SELECT id FROM admins WHERE active = 1" . ($roles ? " AND role IN ('" . implode("','", $roles) . "')" : "") . " ORDER BY id";
    return array_map("intval", $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/** The admin ids a team form went to, lowest first. */
function teamFormRecipientsOf(PDO $pdo, $formId)
{
    $stmt = $pdo->prepare("SELECT admin_id FROM team_form_recipients WHERE form_id = ? ORDER BY admin_id");
    $stmt->execute([(int) $formId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── 1. Sending ───────────────────────────────────────────────────────────

[$status, $r] = post("api/team-forms.php", $sa, ["action" => "create", "title" => "Nope", "scope" => "all", "questions" => $tfQuestions]);
check("team forms: Sam can't send without the permission", $status === 403, "HTTP $status " . json_encode($r));

[$status, $r] = pageGet("api/team-forms.php?action=recipients", $sa);
check("team forms: Sam can't see the picker either", $status === 403, "HTTP $status");

[$status, $r] = pageGet("api/team-forms.php?action=recipients", $owner);
$roleCounts = array_column($r["roles"] ?? [], "count", "role");
$offered = array_column($r["people"] ?? [], "id");
sort($offered);
check("team forms: the picker offers active admins but not the sender", $status === 200
    && $offered === array_values(array_diff(activeAdmins($pdo), [1]))
    && ($roleCounts["account_manager"] ?? null) === count(activeAdmins($pdo, ["account_manager"])) && ($roleCounts["owner"] ?? null) === 0, json_encode($r));

[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => " ", "scope" => "all", "questions" => $tfQuestions]);
check("team forms: a title is required", $status === 400, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Empty", "scope" => "all", "questions" => [["text" => " "]]]);
check("team forms: a question is required", $status === 400, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Roles", "scope" => "roles", "roles" => [], "questions" => $tfQuestions]);
check("team forms: by role needs a role", $status === 400, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Roles", "scope" => "roles", "roles" => ["wizard"], "questions" => $tfQuestions]);
check("team forms: an unknown role is refused", $status === 400, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Dee", "scope" => "specific", "recipient_ids" => [6], "questions" => $tfQuestions]);
check("team forms: a switched-off admin is nobody", $status === 400, "HTTP $status " . json_encode($r));

[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Account managers check-in",
    "scope" => "roles", "roles" => ["account_manager"], "recipient_ids" => [5], "due_date" => day("-1"), "questions" => $tfQuestions]);
$amForm = (int) ($r["id"] ?? 0);
$amExpected = array_values(array_unique(array_merge(activeAdmins($pdo, ["account_manager"]), [5])));
sort($amExpected);
check("team forms: to a role, plus Sean", $status === 200 && teamFormRecipientsOf($pdo, $amForm) === $amExpected, "HTTP $status " . json_encode($r));
check("team forms: the role reached the inbox with a link", notes($pdo, "admin", 3, "team_form") === 1 && notes($pdo, "admin", 2, "team_form") === 0
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE team_form_id = ?", [$amForm])["n"] === count($amExpected)
    && one($pdo, "SELECT link FROM notifications WHERE team_form_id = ? LIMIT 1", [$amForm])["link"] === "admin-team-form.html?id=$amForm");

[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Mixed", "scope" => "roles",
    "roles" => ["seo_admin", "owner"], "recipient_ids" => [2, 5], "questions" => $tfQuestions]);
$mixed = array_values(array_unique(array_merge(activeAdmins($pdo, ["seo_admin"]), [2, 5])));
sort($mixed);
check("team forms: roles plus people, each once, never the sender", $status === 200 && teamFormRecipientsOf($pdo, $r["id"] ?? 0) === $mixed,
    "HTTP $status " . json_encode($r));

[$status, $r] = post("api/team-forms.php", $owner, ["action" => "create", "title" => "Everyone", "scope" => "all", "questions" => $tfQuestions]);
$allForm = (int) ($r["id"] ?? 0);
check("team forms: to the whole team", $status === 200 && teamFormRecipientsOf($pdo, $allForm) === array_values(array_diff(activeAdmins($pdo), [1])), "HTTP $status");

// ── 2. Seeing ────────────────────────────────────────────────────────────

[$status, $r] = pageGet("api/team-forms.php?action=get&id=$amForm", $am);
check("team forms: a recipient sees the questions, not the others", $status === 200 && count($r["form"]["questions"] ?? []) === 3
    && $r["form"]["mine"]["status"] === "pending" && $r["form"]["mine"]["overdue"] === true
    && $r["form"]["sees_answers"] === false && !isset($r["form"]["recipients"]), json_encode($r));
[$status] = pageGet("api/team-forms.php?action=get&id=$amForm", $sa);
check("team forms: someone it wasn't sent to gets a 404", $status === 404, "HTTP $status");
[$status, $r] = pageGet("api/team-forms.php?action=pending_count", $am);
check("team forms: the badge counts what's waiting", $status === 200 && ($r["pending"] ?? null) === 2, json_encode($r));

// ── 3. Answering ─────────────────────────────────────────────────────────

[$q1, $q2, $q3] = array_column(teamFormQuestions($pdo, $amForm), "id");

[$status, $r] = post("api/team-forms.php", $am, ["action" => "submit", "form_id" => $amForm,
    "answers" => json_encode([$q2 => "Figma"])], [], true);
check("team forms: a required answer is required", $status === 400, "HTTP $status " . json_encode($r));

[$status, $r] = post("api/team-forms.php", $am, ["action" => "submit", "form_id" => $amForm,
    "answers" => json_encode([$q1 => "Busy but good.", $q2 => "Figma, Fax machine, Slack", $q3 => "=HYPERLINK(\"x\")"])], [], true);
check("team forms: Amy answers", $status === 200 && $r["form"]["mine"]["status"] === "submitted", "HTTP $status " . json_encode($r));
$picked = one($pdo, "SELECT answer FROM team_form_answers WHERE form_id = ? AND admin_id = 3 AND question_id = ?", [$amForm, $q2]);
check("team forms: only options the form offers are kept", ($picked["answer"] ?? "") === "Figma, Slack", json_encode($picked));
check("team forms: the sender hears about it", notes($pdo, "admin", 1, "team_form_answered") === 1);

[$status] = post("api/team-forms.php", $am, ["action" => "submit", "form_id" => $amForm, "answers" => json_encode([$q1 => "Again", $q3 => "x"])], [], true);
check("team forms: answering twice is a 409", $status === 409, "HTTP $status");
[$status] = post("api/team-forms.php", $sa, ["action" => "submit", "form_id" => $amForm, "answers" => json_encode([$q1 => "Me", $q3 => "x"])], [], true);
check("team forms: someone it wasn't sent to can't answer", $status === 404, "HTTP $status");

[$status, $r] = pageGet("api/team-forms.php?action=get&id=$amForm", $owner);
$amyRow = array_values(array_filter($r["form"]["recipients"] ?? [], function ($x) { return $x["id"] === 3; }))[0] ?? [];
check("team forms: the sender sees who answered and what", $status === 200 && $r["form"]["progress"] === ["answered" => 1, "total" => count($amExpected)]
    && count($amyRow["answers"] ?? []) === 3, json_encode($r));

[$status, $r] = pageGet("api/team-forms.php?action=get&id=$amForm", $am);
check("team forms: Amy sees her own answers, still not anyone else's", $status === 200 && count($r["form"]["mine"]["answers"]) === 3
    && !isset($r["form"]["recipients"]));

[$status, $r] = post("api/team-forms.php", $am, ["action" => "close", "id" => $amForm]);
check("team forms: a recipient can't close it", $status === 403, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "close", "id" => $amForm]);
check("team forms: the sender closes it", $status === 200 && $r["form"]["status"] === "closed", json_encode($r));
[$status] = post("api/team-forms.php", $seo, ["action" => "submit", "form_id" => $amForm, "answers" => json_encode([$q1 => "Late", $q3 => "x"])], [], true);
check("team forms: a closed form takes no answers", $status === 409, "HTTP $status");
[$status, $r] = post("api/team-forms.php", $owner, ["action" => "reopen", "id" => $amForm]);
check("team forms: and reopens it", $status === 200 && $r["form"]["status"] === "open");

[$status, $r] = pageGet("api/team-forms.php?action=list", $am);
check("team forms: Amy's list has what she was sent, nothing she sent", $status === 200
    && in_array($amForm, array_column($r["assigned"] ?? [], "id"), true) && ($r["sent"] ?? null) === [] && $r["can_send"] === false, json_encode($r));

// ── 4. api/v1, and the permission handed to a role ───────────────────────

[$status, $r] = get("team-forms.php", $seo);
check("v1 team forms: the list", $status === 200 && in_array($amForm, array_column($r["data"]["assigned"] ?? [], "id"), true), "HTTP $status " . json_encode($r));
$fixtures["list_team_forms"] = $r["data"] ?? null;

/** The team_form_id on someone's notifications of one event type. */
function teamFormIdsIn(array $reply, $event)
{
    return array_values(array_map(function ($n) { return $n["team_form_id"]; },
        array_filter($reply["data"]["items"] ?? [], function ($n) use ($event) { return $n["event_type"] === $event; })));
}

[$status, $r] = get("notifications.php?limit=50", $seo);
check("v1 team forms: the notification carries team_form_id", $status === 200
    && in_array($amForm, teamFormIdsIn($r, "team_form"), true), "HTTP $status " . json_encode($r));

[$status, $r] = get("notifications.php?limit=50", $owner);
check("v1 team forms: so does the sender's answered notification", $status === 200
    && in_array($amForm, teamFormIdsIn($r, "team_form_answered"), true), "HTTP $status " . json_encode($r));
$plain = array_values(array_filter($r["data"]["items"] ?? [], function ($n) { return strpos($n["event_type"], "team_form") !== 0; }));
check("v1 team forms: other notifications have no team_form_id", !$plain || $plain[0]["team_form_id"] === null);

[$status, $r] = get("team-forms.php?id=$amForm", $owner);
check("v1 team forms: the sender's view wraps what people typed", $status === 200
    && $r["data"]["untrusted_content"]["title"] === "Account managers check-in"
    && ($r["data"]["recipients"][0]["answers"][0]["untrusted_content"]["answer"] ?? "") !== "", "HTTP $status " . json_encode($r));
$fixtures["get_team_form"] = $r["data"] ?? null;

[$status] = get("team-forms.php?id=$amForm", $sa);
check("v1 team forms: a 404 for anyone else", $status === 404, "HTTP $status");

refusedChange("v1 team forms: Sam can't send", $sa, "send_team_form",
    ["title" => "Hi", "questions" => [["text" => "Q?"]]], 403);

[, $done] = change("v1 team forms: Sean answers", $seo, "answer_team_form", ["id" => $amForm, "answers" => [
    ["question_id" => $q1, "answer" => "Steady."], ["question_id" => $q2, "answer" => ["Notion"]], ["question_id" => $q3, "answer" => "No"],
]]);
check("v1 team forms: Sean's answers are in", (one($pdo, "SELECT status FROM team_form_recipients WHERE form_id = ? AND admin_id = 5", [$amForm])["status"] ?? "") === "submitted"
    && (one($pdo, "SELECT answer FROM team_form_answers WHERE form_id = ? AND admin_id = 5 AND question_id = ?", [$amForm, $q2])["answer"] ?? "") === "Notion");

storeRolePermission($pdo, "super_admin", "team_forms.send", true, 1);
forgetPermissions();
[$prep, $done] = change("v1 team forms: given the permission, Sam sends one", $sa, "send_team_form", [
    "scope" => "specific", "recipients" => [5], "title" => "Sean's onboarding", "questions" => [["text" => "Settled in?"]],
]);
$samForm = (int) ($done["data"]["result"]["team_form_id"] ?? 0);
check("v1 team forms: the summary names the person", strpos($prep["data"]["summary"] ?? "", "Sean") !== false, json_encode($prep));
check("v1 team forms: only Sean got it", teamFormRecipientsOf($pdo, $samForm) === [5]);

[$status, $r] = pageGet("api/team-forms.php?action=get&id=$samForm", $owner);
check("team forms: the Director sees a form someone else sent", $status === 200 && $r["form"]["sees_answers"] === true, "HTTP $status");
[$status] = pageGet("api/team-forms.php?action=get&id=$samForm", $am);
check("team forms: another admin doesn't", $status === 404, "HTTP $status");

$pdo->exec("DELETE FROM role_permissions WHERE role = 'super_admin' AND permission = 'team_forms.send'");
forgetPermissions();
