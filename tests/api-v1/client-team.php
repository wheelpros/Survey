<?php

/*
| Client teams - required by run.php after branding.php, sharing its scope.
|
| The first person registered can add up to four teammates. Everyone on an
| account works on the same records, each with their own login; nobody sees
| another account's; only the owner manages the team; and staff still see one
| client per account.
*/

seed($pdo, "users", [
    ["id" => 30, "name" => "Olga Owner", "email" => "olga@omega.test", "approved" => 1, "session_token" => "tok-olga",
     "company_name" => "Omega Ltd", "website" => "https://omega.test"],
    ["id" => 31, "name" => "Tim Mate", "email" => "tim@omega.test", "approved" => 1, "session_token" => "tok-tim", "account_owner_id" => 30,
     "password" => password_hash("tim-password", PASSWORD_DEFAULT)],
    ["id" => 40, "name" => "Rita Rival", "email" => "rita@rival.test", "approved" => 1, "session_token" => "tok-rita", "company_name" => "Rival Inc"],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 3, "user_id" => 30]]);
seed($pdo, "surveys", [
    ["id" => 140, "title" => "Omega brief", "assigned_user_id" => 30, "status" => "pending", "created_by_admin_id" => 3],
    ["id" => 141, "title" => "Rival brief", "assigned_user_id" => 40, "status" => "pending", "created_by_admin_id" => 4],
    ["id" => 142, "title" => "Omega second", "assigned_user_id" => 30, "status" => "pending", "created_by_admin_id" => 3],
]);
seed($pdo, "survey_questions", [
    ["id" => 1400, "survey_id" => 140, "question_text" => "Goals", "question_type" => "input", "sort_order" => 1, "chips" => "[]"],
    ["id" => 1420, "survey_id" => 142, "question_text" => "More", "question_type" => "input", "sort_order" => 1, "chips" => "[]"],
]);
seed($pdo, "projects", [
    ["id" => 540, "client_id" => 30, "title" => "Omega site", "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 3, "created_by_admin_id" => 3],
    ["id" => 541, "client_id" => 40, "title" => "Rival site", "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 4, "created_by_admin_id" => 4],
]);
seed($pdo, "appointments", [
    ["id" => 440, "user_id" => 30, "title" => "Kickoff", "topic" => "Kickoff", "date" => day("+4"), "time" => "10:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 3],
    ["id" => 441, "user_id" => 40, "title" => "Rival", "topic" => "Rival", "date" => day("+4"), "time" => "11:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 4],
]);
seed($pdo, "content", [
    ["id" => 330, "title" => "Omega post", "client" => "Omega Ltd", "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 3],
    ["id" => 331, "title" => "Rival post", "client" => "Rival Inc", "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 4],
]);

function surveyTitles(array $reply)
{
    return array_column($reply["surveys"] ?? $reply["data"]["surveys"] ?? [], "title");
}

// ── Everyone on an account sees the same work ────────────────────────────

[, $r] = pageGet("api/dashboard.php", "tok-tim");
$titles = surveyTitles($r);
check("team: a teammate sees the account's forms", in_array("Omega brief", $titles, true) && !in_array("Rival brief", $titles, true), json_encode($titles));

[, $r] = pageGet("api/dashboard.php", "tok-rita");
check("team: another account sees none of them", !in_array("Omega brief", surveyTitles($r), true), json_encode(surveyTitles($r)));

[, $r] = pageGet("api/survey-details.php?id=140", "tok-tim");
check("team: a teammate opens the account's form", ($r["survey"]["title"] ?? "") === "Omega brief", json_encode($r));
[, $r] = pageGet("api/survey-details.php?id=141", "tok-tim");
check("team: but not another account's", empty($r["survey"]), json_encode($r));

[, $r] = pageGet("api/user-projects.php", "tok-tim");
$projects = array_column($r["data"]["projects"] ?? $r["data"] ?? $r["projects"] ?? [], "title");
check("team: a teammate sees the account's projects", in_array("Omega site", $projects, true) && !in_array("Rival site", $projects, true), json_encode($r));

[, $r] = pageGet("api/user-content.php", "tok-tim");
$posts = array_column($r["content"] ?? $r["data"]["items"] ?? $r["data"] ?? [], "title");
check("team: a teammate sees the company's posts", in_array("Omega post", $posts, true) && !in_array("Rival post", $posts, true), json_encode($posts));

[, $r] = pageGet("api/calendar.php?action=get_user_calendar", "tok-tim");
$meetings = array_column($r["appointments"] ?? $r["data"] ?? [], "id");
check("team: a teammate sees the account's meetings", in_array(440, array_map("intval", $meetings), true) && !in_array(441, array_map("intval", $meetings), true), json_encode($r));

[, $r] = pageGet("api/check-auth.php", "tok-tim");
check("team: a teammate is themselves, at the owner's company", ($r["user"]["name"] ?? "") === "Tim Mate"
    && ($r["user"]["company_name"] ?? "") === "Omega Ltd" && ($r["user"]["website"] ?? "") === "https://omega.test"
    && ($r["user"]["is_account_owner"] ?? null) === false, json_encode($r));
[, $r] = pageGet("api/check-auth.php", "tok-olga");
check("team: the owner is marked as the owner", ($r["user"]["is_account_owner"] ?? null) === true, json_encode($r));

// Signing in as a teammate doesn't sign the owner out.
[, $r] = post("api/login.php", "", ["email" => "tim@omega.test", "password" => "tim-password"]);
check("team: a teammate signs in", ($r["success"] ?? false) === true && ($r["user"]["company_name"] ?? "") === "Omega Ltd", json_encode($r));
// That login replaced the seeded token; back to one the checks below can name.
$pdo->exec("UPDATE users SET session_token = 'tok-tim' WHERE id = 31");
$timToken = "tok-tim";
[, $r] = pageGet("api/check-auth.php", "tok-olga");
check("team: and the owner stays signed in", ($r["success"] ?? false) === true);

// ── Who does what ────────────────────────────────────────────────────────

[, $r] = post("api/submit-survey.php", $timToken, ["surveyId" => 140, "answers" => json_encode([["questionId" => 1400, "questionLabel" => "Goals", "answer" => "Grow"]])], [], true);
check("team: a teammate submits the account's form", ($r["message"] ?? "") === "Survey submitted successfully", json_encode($r));
$row = one($pdo, "SELECT user_id, submitted_by_id, submitted_by_name FROM survey_responses WHERE survey_id = 140");
check("team: it belongs to the account, and names who did it", (int) $row["user_id"] === 30 && (int) $row["submitted_by_id"] === 31 && $row["submitted_by_name"] === "Tim Mate", json_encode($row));

[$status, $r] = post("api/submit-survey.php", "tok-olga", ["surveyId" => 140, "answers" => json_encode([["questionId" => 1400, "questionLabel" => "Goals", "answer" => "Shrink"]])], [], true);
check("team: a second submission is refused", ($r["success"] ?? true) === false && strpos((string) ($r["message"] ?? ""), "already been submitted") !== false, "HTTP $status " . json_encode($r));
check("team: and saved nothing", one($pdo, "SELECT COUNT(*) AS n FROM survey_responses WHERE survey_id = 140")["n"] == 1);

[, $r] = pageGet("api/survey-details.php?id=140", "tok-olga");
check("team: the owner reads the teammate's answers, and whose they are", ($r["submitted_by"] ?? "") === "Tim Mate"
    && ($r["answers"][0]["answer"] ?? "") === "Grow", json_encode($r));

check("team: staff hear from the person, with the account's staff told", notes($pdo, "admin", 3, "form_submitted") >= 1);
$note = one($pdo, "SELECT title FROM notifications WHERE recipient_kind = 'admin' AND recipient_id = 3 AND event_type = 'form_submitted' ORDER BY id DESC LIMIT 1");
check("team: by name", ($note["title"] ?? "") === "New response from Tim Mate", json_encode($note));

[, $r] = post("api/calendar.php?action=respond_appointment", "tok-tim", ["id" => 440, "status" => "approved"]);
$row = one($pdo, "SELECT status, responded_by_id, responded_by_name, responded_at FROM appointments WHERE id = 440");
check("team: a teammate answers the account's meeting", $row["status"] === "approved" && (int) $row["responded_by_id"] === 31
    && $row["responded_by_name"] === "Tim Mate" && $row["responded_at"] !== null, json_encode([$r, $row]));

[, $r] = post("api/calendar.php?action=respond_appointment", "tok-olga", ["id" => 440, "status" => "rejected"]);
$row = one($pdo, "SELECT status, responded_by_name FROM appointments WHERE id = 440");
check("team: the first answer stands", ($r["success"] ?? true) === false && $row["status"] === "approved" && $row["responded_by_name"] === "Tim Mate", json_encode([$r, $row]));

[, $r] = post("api/calendar.php?action=respond_appointment", "tok-tim", ["id" => 441, "status" => "approved"]);
check("team: another account's meeting isn't theirs to answer", ($r["success"] ?? true) === false
    && one($pdo, "SELECT status FROM appointments WHERE id = 441")["status"] === "pending", json_encode($r));

[, $r] = post("api/calendar.php?action=create_user_request", "tok-tim", ["date" => day("+6"), "time" => "12:00", "topic" => "Tim asks"]);
$row = one($pdo, "SELECT user_id, requested_by_id, requested_by_name, client FROM appointments WHERE topic = 'Tim asks'");
check("team: a teammate's meeting request is the account's", (int) $row["user_id"] === 30 && (int) $row["requested_by_id"] === 31 && $row["client"] === "Omega Ltd", json_encode($row));

// ── Everyone hears ───────────────────────────────────────────────────────

$pdo->exec("DELETE FROM notifications WHERE recipient_kind = 'user' AND recipient_id IN (30, 31, 40)");
require_once $root . "/api/notify.php";
notifyClientAccount($pdo, 30, "x", "To the account");
check("team: a client notification reaches everyone on the account", notes($pdo, "user", 30, "x") === 1 && notes($pdo, "user", 31, "x") === 1 && notes($pdo, "user", 40, "x") === 0);
notifyUsersAtCompany($pdo, "Omega Ltd", "y", "Company post");
check("team: a company post does too", notes($pdo, "user", 30, "y") === 1 && notes($pdo, "user", 31, "y") === 1 && notes($pdo, "user", 40, "y") === 0);

// ── Company details stay the owner's ─────────────────────────────────────

[, $r] = post("api/update-profile.php", "tok-tim", ["name" => "Tim M", "website" => "evil.test", "description" => "Changed", "company_name" => "Rival Inc", "phone" => "+44 20 1234 5678"], [], true);
$tim = one($pdo, "SELECT name, phone, company_name, website FROM users WHERE id = 31");
$olga = one($pdo, "SELECT website, company_name FROM users WHERE id = 30");
check("team: a teammate edits their own details", ($r["success"] ?? false) === true && $tim["name"] === "Tim M" && $tim["phone"] === "+44 20 1234 5678", json_encode([$r, $tim]));
check("team: but no company's", $tim["company_name"] === null && $tim["website"] === null && $olga["website"] === "https://omega.test" && $olga["company_name"] === "Omega Ltd", json_encode([$tim, $olga]));
$pdo->exec("UPDATE users SET name = 'Tim Mate' WHERE id = 31");

// ── Inviting ─────────────────────────────────────────────────────────────

/** The newest invite's token, written straight to the row: the real one only goes by email. */
function plantInviteToken(PDO $pdo, $email, $token)
{
    $pdo->prepare("UPDATE client_invites SET token_hash = ? WHERE email = ? ORDER BY id DESC LIMIT 1")->execute([hash("sha256", $token), $email]);
}

function myTeam($token, array $body)
{
    return post("api/my-team.php", $token, $body);
}

function acceptPage($query)
{
    global $port;
    $body = @file_get_contents("http://127.0.0.1:$port/api/accept-invite.php?$query", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]]));
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? "", $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
}

[$status, $team] = pageGet("api/my-team.php", "tok-tim");
check("team: a teammate sees the people", count($team["team"]["people"] ?? []) === 2 && ($team["team"]["can_manage"] ?? null) === false && $team["team"]["invites"] === [], json_encode($team));
check("team: owner first", ($team["team"]["people"][0]["is_owner"] ?? false) === true && $team["team"]["people"][0]["name"] === "Olga Owner", json_encode($team));

[$status, $r] = myTeam("tok-tim", ["action" => "invite", "email" => "x@omega.test"]);
check("team: a teammate can't invite", $status === 403 && !one($pdo, "SELECT id FROM client_invites WHERE email = 'x@omega.test'"), "HTTP $status " . json_encode($r));
[$status, $r] = myTeam("tok-tim", ["action" => "remove", "id" => 31]);
check("team: or remove", $status === 403 && one($pdo, "SELECT id FROM users WHERE id = 31"), "HTTP $status " . json_encode($r));
[$status] = myTeam("nope", ["action" => "invite", "email" => "x@omega.test"]);
check("team: nor can anyone signed out", $status === 401, "HTTP $status");

[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "nope"]);
check("team: a bad address is refused", $status === 422, "HTTP $status " . json_encode($r));
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "rita@rival.test"]);
check("team: a client's address is refused", $status === 409, "HTTP $status " . json_encode($r));
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "O@W.test"]);
check("team: so is an admin's, in any case", $status === 409, "HTTP $status " . json_encode($r));

[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "New.One@omega.test"]);
check("team: the owner invites", $status === 200 && ($r["success"] ?? false) === true, "HTTP $status " . json_encode($r));
check("team: no mail server, and the page is told", ($r["emailed"] ?? null) === false, json_encode($r));
$invite = one($pdo, "SELECT * FROM client_invites WHERE email = 'new.one@omega.test'");
check("team: the invite holds no usable token and no login yet", strlen($invite["token_hash"]) === 64 && !one($pdo, "SELECT id FROM users WHERE email = 'new.one@omega.test'"), json_encode($invite));

// The mail server is down here, so a resend must leave the link already out there working.
[$status, $r] = myTeam("tok-olga", ["action" => "resend", "id" => (int) $invite["id"]]);
$afterResend = one($pdo, "SELECT token_hash, expires_at FROM client_invites WHERE id = ?", [$invite["id"]]);
check("team: a failed resend keeps the old link", $status === 200 && ($r["emailed"] ?? null) === false
    && $afterResend["token_hash"] === $invite["token_hash"] && (int) $afterResend["expires_at"] === (int) $invite["expires_at"],
    "HTTP $status " . json_encode([$r, $afterResend]));
check("team: and says the earlier link still stands", strpos($r["message"] ?? "", "earlier link") !== false, json_encode($r));

// A database error is reported as ours, not as the person's connection.
$pdo->exec("ALTER TABLE client_invites CHANGE expires_at expires_at_moved INT NOT NULL");
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "broken@omega.test"]);
$pdo->exec("ALTER TABLE client_invites CHANGE expires_at_moved expires_at INT NOT NULL");
check("team: a server error comes back as readable JSON", $status === 500 && ($r["success"] ?? true) === false
    && strpos($r["message"] ?? "", "on our side") !== false, "HTTP $status " . json_encode($r));

// The text-only version of an email keeps its link.
require_once $root . "/api/mailer.php";
$plain = portalEmailPlainText("<div>\n  <h2>Join Acme</h2>\n  <p>Set a password &amp; join.</p>\n  <a href='http://portal.test/join-team.html?token=abc&amp;x=1' style='color:#fff'>Accept the invitation</a>\n</div>");
check("email: plain text keeps the link in full", strpos($plain, "Accept the invitation: http://portal.test/join-team.html?token=abc&x=1") !== false, $plain);
$plain2 = portalEmailPlainText("<table><tr><td style='x'>Company</td><td dir='auto'>Acme Ltd</td></tr></table>");
check("email: plain text keeps a label apart from its value", $plain2 === "Company: Acme Ltd", $plain2);
check("email: plain text has no markup or indentation", strpos($plain, "<") === false && strpos($plain, "  ") === false
    && strpos($plain, "Set a password & join.") !== false, $plain);

[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "new.one@omega.test"]);
check("team: the same address twice is refused", $status === 409, "HTTP $status " . json_encode($r));

// Tim plus three invites fills the four seats, invites counting.
myTeam("tok-olga", ["action" => "invite", "email" => "two@omega.test"]);
myTeam("tok-olga", ["action" => "invite", "email" => "three@omega.test"]);
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "four@omega.test"]);
check("team: a fifth teammate is refused, invites counting", $status === 409 && strpos($r["message"], "4 teammates") !== false
    && !one($pdo, "SELECT id FROM client_invites WHERE email = 'four@omega.test'"), "HTTP $status " . json_encode($r));

[$status, $r] = myTeam("tok-rita", ["action" => "revoke", "id" => (int) one($pdo, "SELECT id FROM client_invites WHERE email = 'three@omega.test'")["id"]]);
check("team: another account can't revoke it", $status === 404, "HTTP $status " . json_encode($r));

$threeId = (int) one($pdo, "SELECT id FROM client_invites WHERE email = 'three@omega.test'")["id"];
[$status, $r] = myTeam("tok-olga", ["action" => "revoke", "id" => $threeId]);
check("team: revoking an invite", $status === 200, "HTTP $status " . json_encode($r));
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "four@omega.test"]);
check("team: frees its seat", $status === 200, "HTTP $status " . json_encode($r));

// An expired invite frees its seat too, and resending has to find it one.
$pdo->exec("UPDATE client_invites SET expires_at = " . (time() - 10) . " WHERE email = 'two@omega.test'");
$twoId = (int) one($pdo, "SELECT id FROM client_invites WHERE email = 'two@omega.test'")["id"];
[$status, $r] = myTeam("tok-olga", ["action" => "invite", "email" => "five@omega.test"]);
check("team: an expired invite frees its seat", $status === 200, "HTTP $status " . json_encode($r));
[$status, $r] = myTeam("tok-olga", ["action" => "resend", "id" => $twoId]);
check("team: so resending it can find the team full", $status === 409, "HTTP $status " . json_encode($r));

// Each attempt can send an email to an address the caller typed.
for ($i = 0, $status = 0; $i < 25 && $status !== 429; $i++) {
    [$status] = myTeam("tok-rita", ["action" => "invite", "email" => "nope"]);
}
check("team: invites are rate limited", $status === 429, "HTTP $status after $i");

// ── Joining ──────────────────────────────────────────────────────────────

$joinToken = str_repeat("a", 64);
plantInviteToken($pdo, "new.one@omega.test", $joinToken);

[$status, $r] = acceptPage("token=" . $joinToken);
check("join: the link shows who invited them", $status === 200 && $r["invite"]["email"] === "new.one@omega.test"
    && $r["invite"]["invited_by"] === "Olga Owner" && $r["invite"]["company"] === "Omega Ltd", "HTTP $status " . json_encode($r));
[$status] = acceptPage("token=" . str_repeat("b", 64));
check("join: a made-up token isn't", $status === 410, "HTTP $status");

[$status, $r] = post("api/accept-invite.php", "", ["token" => $joinToken, "name" => "New One", "password" => "short"]);
check("join: a short password is refused", $status === 422 && !one($pdo, "SELECT id FROM users WHERE email = 'new.one@omega.test'"), "HTTP $status " . json_encode($r));

$threeToken = str_repeat("c", 64);
$pdo->prepare("UPDATE client_invites SET token_hash = ? WHERE id = ?")->execute([hash("sha256", $threeToken), $threeId]);
[$status] = post("api/accept-invite.php", "", ["token" => $threeToken, "name" => "Three", "password" => "long-enough-1"]);
check("join: a revoked link is refused", $status === 410 && !one($pdo, "SELECT id FROM users WHERE email = 'three@omega.test'"), "HTTP $status");

$fiveToken = str_repeat("d", 64);
plantInviteToken($pdo, "five@omega.test", $fiveToken);
$pdo->exec("UPDATE client_invites SET expires_at = " . (time() - 10) . " WHERE email = 'five@omega.test'");
[$status] = post("api/accept-invite.php", "", ["token" => $fiveToken, "name" => "Five", "password" => "long-enough-1"]);
check("join: an expired link is refused", $status === 410 && !one($pdo, "SELECT id FROM users WHERE email = 'five@omega.test'"), "HTTP $status");

[$status, $r] = post("api/accept-invite.php", "", ["token" => $joinToken, "name" => "New One", "password" => "new-one-password"]);
$person = one($pdo, "SELECT id, approved, account_owner_id, company_name FROM users WHERE email = 'new.one@omega.test'");
check("join: accepting makes a login on the account, needing no approval", $status === 200 && (int) $person["approved"] === 1
    && (int) $person["account_owner_id"] === 30 && $person["company_name"] === null, "HTTP $status " . json_encode([$r, $person]));
check("join: and tells the owner and their staff", notes($pdo, "user", 30, "client_team_changed") === 1 && notes($pdo, "admin", 3, "client_team_changed") >= 1);

[$status] = post("api/accept-invite.php", "", ["token" => $joinToken, "name" => "Again", "password" => "new-one-password"]);
check("join: a used link is refused", $status === 410, "HTTP $status");

[, $r] = post("api/login.php", "", ["email" => "new.one@omega.test", "password" => "new-one-password"]);
check("join: and they can sign in", ($r["success"] ?? false) === true && ($r["user"]["is_account_owner"] ?? null) === false, json_encode($r));
$newToken = $r["token"] ?? "";

// An address that gets an account of its own while invited can't also join.
$fourToken = str_repeat("e", 64);
plantInviteToken($pdo, "four@omega.test", $fourToken);
seed($pdo, "users", [["id" => 49, "name" => "Four", "email" => "four@omega.test", "approved" => 0]]);
[$status] = post("api/accept-invite.php", "", ["token" => $fourToken, "name" => "Four", "password" => "long-enough-1"]);
check("join: an address taken since is refused", $status === 409, "HTTP $status");
$pdo->exec("DELETE FROM users WHERE id = 49");

// ── Staff see one client ─────────────────────────────────────────────────

$clients = ids(get("clients.php", $owner)[1]);
check("staff: one client per account", in_array(30, $clients, true) && !in_array(31, $clients, true), json_encode($clients));
sees("staff: a teammate's email finds the account", "clients.php?query=tim@omega", $owner, [30]);
refused("staff: a teammate isn't a client of their own", "clients.php?id=31", $owner, 404);

[, $r] = pageGet("api/admin-users.php", $owner);
$listed = array_map("intval", array_column($r["users"] ?? [], "id"));
check("staff: the client list shows the account once", in_array(30, $listed, true) && !in_array(31, $listed, true), json_encode($listed));

[, $r] = pageGet("api/admin-user-details.php?id=30", $owner);
check("staff: the client's page lists their people and invites", in_array("Tim Mate", array_column($r["people"] ?? [], "name"), true)
    && in_array("four@omega.test", array_column($r["invites"] ?? [], "email"), true), json_encode(array_intersect_key($r, ["people" => 1, "invites" => 1])));
[$status] = pageGet("api/admin-user-details.php?id=31", $owner);
check("staff: a teammate has no client page", $status === 404, "HTTP $status");

[, $r] = post("api/admin-users.php", $owner, ["action" => "reject", "user_id" => 31], ["X-HTTP-Method-Override" => "POST"]);
check("staff: reject can't touch a teammate", one($pdo, "SELECT id FROM users WHERE id = 31") !== false, json_encode($r));

[, $r] = post("api/delete-client.php", $owner, ["user_id" => 31, "confirm_email" => "tim@omega.test"]);
check("staff: nor can delete-client", ($r["success"] ?? true) === false && one($pdo, "SELECT id FROM users WHERE id = 31") !== false, json_encode($r));

// ── Removing ─────────────────────────────────────────────────────────────

setSiteSetting($pdo, "mcp_clients_enabled", "1");
$timMcp = "wzat_" . str_repeat("t", 43);
seed($pdo, "mcp_grants", [["id" => 40, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 31, "scopes" => "self:read self:write", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($timMcp), "kind" => "access", "grant_id" => 40, "expires_at" => time() + 3600]]);

[$status, $ov] = get("me/overview.php", $timMcp, ["X-MCP-Key" => $mcpKey]);
check("mcp: a teammate's connection acts as them, on the account", $status === 200 && $ov["data"]["me"]["name"] === "Tim Mate"
    && $ov["data"]["me"]["company_name"] === "Omega Ltd", "HTTP $status " . json_encode($ov));
[$status, $forms] = get("me/forms.php", $timMcp, ["X-MCP-Key" => $mcpKey]);
check("mcp: seeing the account's forms", $status === 200 && in_array(142, ids($forms), true) && !in_array(141, ids($forms), true), "HTTP $status " . json_encode($forms));

[$status, $r] = myTeam("tok-rita", ["action" => "remove", "id" => 31]);
check("remove: another account can't", $status === 403 || $status === 404, "HTTP $status");
[$status, $r] = myTeam("tok-olga", ["action" => "remove", "id" => 30]);
check("remove: the owner can't be removed", $status === 404 && one($pdo, "SELECT id FROM users WHERE id = 30"), "HTTP $status");

[$status, $r] = myTeam("tok-olga", ["action" => "remove", "id" => 31]);
check("remove: the owner removes a teammate", $status === 200 && !one($pdo, "SELECT id FROM users WHERE id = 31"), "HTTP $status " . json_encode($r));

[, $r] = pageGet("api/check-auth.php", $timToken);
check("remove: their session stops", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/login.php", "", ["email" => "tim@omega.test", "password" => "tim-password"]);
check("remove: so does their password", ($r["success"] ?? true) === false, json_encode($r));
[$status] = get("me/overview.php", $timMcp, ["X-MCP-Key" => $mcpKey]);
check("remove: and their AI connection", $status === 401, "HTTP $status");
[, $r] = pageGet("api/survey-details.php?id=140", "tok-olga");
check("remove: their past answers stay, under their name", ($r["submitted_by"] ?? "") === "Tim Mate" && ($r["answers"][0]["answer"] ?? "") === "Grow", json_encode($r));

[, $r] = post("api/admin-user-details.php", $am2, ["action" => "remove_teammate", "id" => 30, "person_id" => (int) $person["id"]]);
check("remove: staff not on this client can't", ($r["success"] ?? true) === false && one($pdo, "SELECT id FROM users WHERE id = ?", [$person["id"]]), json_encode($r));
[, $r] = post("api/admin-user-details.php", $am, ["action" => "remove_teammate", "id" => 30, "person_id" => (int) $person["id"]]);
check("remove: staff on this client can", ($r["success"] ?? false) === true && !one($pdo, "SELECT id FROM users WHERE id = ?", [$person["id"]]), json_encode($r));
check("remove: and it's on the event log", (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'client.teammate_removed' AND client_id = 30"));
[, $r] = pageGet("api/check-auth.php", $newToken);
check("remove: their session stops too", ($r["success"] ?? true) === false, json_encode($r));

// ── Deleting an owner takes the account ──────────────────────────────────

seed($pdo, "users", [["id" => 32, "name" => "Last Mate", "email" => "last@omega.test", "approved" => 1, "session_token" => "tok-last", "account_owner_id" => 30]]);
seed($pdo, "notifications", [["id" => 19700, "recipient_kind" => "user", "recipient_id" => 32, "event_type" => "x", "title" => "For Last"]]);
[, $r] = post("api/delete-client.php", $owner, ["user_id" => 30, "confirm_email" => "olga@omega.test"]);
check("delete: an owner's account goes", ($r["success"] ?? false) === true && !one($pdo, "SELECT id FROM users WHERE id = 30"), json_encode($r));
check("delete: with their teammates", !one($pdo, "SELECT id FROM users WHERE account_owner_id = 30"));
check("delete: and their invites", !one($pdo, "SELECT id FROM client_invites WHERE account_id = 30"));
check("delete: and the teammates' notifications", !one($pdo, "SELECT id FROM notifications WHERE id = 19700"));
[, $r] = pageGet("api/check-auth.php", "tok-last");
check("delete: nobody on it stays signed in", ($r["success"] ?? true) === false, json_encode($r));
