<?php

/*
| Director tools and team MCP tools - required by run.php after client-team.php,
| sharing its scope.
|
| Changing an owner swaps two people's rows and leaves the account's records
| alone. A merge moves one account onto another or does nothing at all. Only the
| Director does either, and an assistant changes a team only once it's confirmed.
*/

/** One account action on a client's page, as $token. */
function accountAction($token, $id, array $body)
{
    return post("api/admin-user-details.php", $token, array_merge(["id" => $id], $body));
}

function scalar(PDO $pdo, $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

seed($pdo, "users", [
    ["id" => 50, "name" => "Odin Owner", "email" => "odin@sigma.test", "approved" => 1, "session_token" => "tok-odin",
     "company_name" => "Sigma Ltd", "website" => "https://sigma.test", "phone" => "+44 1", "password" => password_hash("odin-password", PASSWORD_DEFAULT)],
    ["id" => 51, "name" => "Tess Mate", "email" => "tess@sigma.test", "approved" => 1, "session_token" => "tok-tess", "account_owner_id" => 50,
     "phone" => "+44 2", "password" => password_hash("tess-password", PASSWORD_DEFAULT)],
    ["id" => 52, "name" => "Ned Mate", "email" => "ned@sigma.test", "approved" => 1, "session_token" => "tok-ned", "account_owner_id" => 50],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 3, "user_id" => 50]]);
seed($pdo, "surveys", [["id" => 150, "title" => "Sigma brief", "assigned_user_id" => 50, "status" => "completed", "created_by_admin_id" => 3]]);
// From before teams: no submitted_by, so the owner did it.
seed($pdo, "survey_responses", [["id" => 250, "user_id" => 50, "survey_id" => 150, "survey_title" => "Sigma brief"]]);
seed($pdo, "projects", [["id" => 550, "client_id" => 50, "title" => "Sigma site", "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 3, "created_by_admin_id" => 3]]);
seed($pdo, "notifications", [
    ["id" => 19800, "recipient_kind" => "user", "recipient_id" => 50, "event_type" => "x", "title" => "For Odin"],
    ["id" => 19801, "recipient_kind" => "user", "recipient_id" => 51, "event_type" => "x", "title" => "For Tess"],
    ["id" => 19802, "recipient_kind" => "admin", "recipient_id" => 50, "event_type" => "x", "title" => "For admin 50"],
]);

setSiteSetting($pdo, "mcp_clients_enabled", "1");
$odinMcp = "wzat_" . str_repeat("o", 43);
$tessMcp = "wzat_" . str_repeat("p", 43);
seed($pdo, "mcp_grants", [
    ["id" => 50, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 50, "scopes" => "self:read self:write", "resource" => "https://mcp.test/mcp"],
    ["id" => 51, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 51, "scopes" => "self:read self:write", "resource" => "https://mcp.test/mcp"],
]);
seed($pdo, "mcp_tokens", [
    ["token_hash" => tokenHash($odinMcp), "kind" => "access", "grant_id" => 50, "expires_at" => time() + 3600],
    ["token_hash" => tokenHash($tessMcp), "kind" => "access", "grant_id" => 51, "expires_at" => time() + 3600],
]);

// ── Only the Director ────────────────────────────────────────────────────

[$status, $r] = accountAction($am, 50, ["action" => "change_owner", "person_id" => 51]);
check("owner: an account manager can't change it", $status === 403 && (int) scalar($pdo, "SELECT account_owner_id FROM users WHERE id = 51") === 50, "HTTP $status " . json_encode($r));
[$status] = accountAction($am, 50, ["action" => "merge", "source_id" => 40]);
check("merge: an account manager can't either", $status === 403 && one($pdo, "SELECT id FROM projects WHERE id = 541 AND client_id = 40"), "HTTP $status");
[$status] = accountAction("tok-odin", 50, ["action" => "change_owner", "person_id" => 51]);
check("owner: a client can't", $status === 401, "HTTP $status");

[$status] = accountAction($owner, 50, ["action" => "change_owner", "person_id" => 11]);
check("owner: only someone on this team", $status === 404, "HTTP $status");
[$status] = accountAction($owner, 50, ["action" => "change_owner", "person_id" => 50]);
check("owner: and not the owner themselves", $status === 404, "HTTP $status");

// ── Changing the owner ───────────────────────────────────────────────────

[$status, $r] = accountAction($owner, 50, ["action" => "change_owner", "person_id" => 51]);
check("owner: the Director changes it", $status === 200 && ($r["success"] ?? false) === true, "HTTP $status " . json_encode($r));

$row50 = one($pdo, "SELECT name, email, phone, company_name, website, account_owner_id FROM users WHERE id = 50");
$row51 = one($pdo, "SELECT name, email, phone, company_name, website, account_owner_id FROM users WHERE id = 51");
check("owner: the new owner has the account's row, and the company stays on it", $row50["name"] === "Tess Mate" && $row50["email"] === "tess@sigma.test"
    && $row50["phone"] === "+44 2" && $row50["company_name"] === "Sigma Ltd" && $row50["website"] === "https://sigma.test" && $row50["account_owner_id"] === null, json_encode($row50));
check("owner: the old owner is a teammate", $row51["name"] === "Odin Owner" && $row51["email"] === "odin@sigma.test"
    && $row51["phone"] === "+44 1" && $row51["company_name"] === null && (int) $row51["account_owner_id"] === 50, json_encode($row51));

[, $r] = pageGet("api/check-auth.php", "tok-odin");
check("owner: the old owner stays signed in as themselves", ($r["user"]["name"] ?? "") === "Odin Owner" && ($r["user"]["is_account_owner"] ?? null) === false
    && ($r["user"]["company_name"] ?? "") === "Sigma Ltd", json_encode($r));
[, $r] = pageGet("api/check-auth.php", "tok-tess");
check("owner: so does the new one", ($r["user"]["name"] ?? "") === "Tess Mate" && ($r["user"]["is_account_owner"] ?? null) === true, json_encode($r));

[, $r] = post("api/login.php", "", ["email" => "tess@sigma.test", "password" => "tess-password"]);
check("owner: passwords stay with their people", ($r["success"] ?? false) === true && ($r["user"]["is_account_owner"] ?? null) === true, json_encode($r));
$pdo->exec("UPDATE users SET session_token = 'tok-tess' WHERE email = 'tess@sigma.test'");

[$status, $r] = get("me/overview.php", $odinMcp, ["X-MCP-Key" => $mcpKey]);
check("owner: an AI connection stays with its person", $status === 200 && $r["data"]["me"]["name"] === "Odin Owner", "HTTP $status " . json_encode($r));
[$status, $r] = get("me/overview.php", $tessMcp, ["X-MCP-Key" => $mcpKey]);
check("owner: both of them", $status === 200 && $r["data"]["me"]["name"] === "Tess Mate", "HTTP $status " . json_encode($r));

check("owner: notifications stay with their people", (int) scalar($pdo, "SELECT recipient_id FROM notifications WHERE id = 19800") === 51
    && (int) scalar($pdo, "SELECT recipient_id FROM notifications WHERE id = 19801") === 50);
check("owner: an admin with the same number keeps theirs", (int) scalar($pdo, "SELECT recipient_id FROM notifications WHERE id = 19802") === 50);

$history = one($pdo, "SELECT user_id, submitted_by_id, submitted_by_name FROM survey_responses WHERE id = 250");
check("owner: old responses keep their real author", (int) $history["user_id"] === 50 && (int) $history["submitted_by_id"] === 51
    && $history["submitted_by_name"] === "Odin Owner", json_encode($history));
check("owner: the account's records don't move", (int) scalar($pdo, "SELECT client_id FROM projects WHERE id = 550") === 50
    && (int) scalar($pdo, "SELECT assigned_user_id FROM surveys WHERE id = 150") === 50);
check("owner: it's on the event log", (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'client.owner_changed' AND client_id = 50"));
check("owner: both are told", notes($pdo, "user", 50, "client_team_changed") >= 1 && notes($pdo, "user", 51, "client_team_changed") >= 1);

[$status, $r] = post("api/my-team.php", "tok-odin", ["action" => "invite", "email" => "x@sigma.test"]);
check("owner: the old owner can't manage the team now", $status === 403, "HTTP $status " . json_encode($r));
[$status, $r] = post("api/my-team.php", "tok-tess", ["action" => "remove", "id" => 51]);
check("owner: the new one can, and can remove them", $status === 200 && !one($pdo, "SELECT id FROM users WHERE id = 51"), "HTTP $status " . json_encode($r));
[$status] = get("me/overview.php", $odinMcp, ["X-MCP-Key" => $mcpKey]);
check("owner: which ends their AI connection too", $status === 401, "HTTP $status");

// ── Merging ──────────────────────────────────────────────────────────────

seed($pdo, "users", [
    ["id" => 60, "name" => "Sal Source", "email" => "sal@source.test", "approved" => 1, "session_token" => "tok-sal", "company_name" => "Sigma Limited", "password" => password_hash("sal-password", PASSWORD_DEFAULT)],
    ["id" => 61, "name" => "Sam Source", "email" => "sam@source.test", "approved" => 1, "session_token" => "tok-sam", "account_owner_id" => 60],
    ["id" => 70, "name" => "Big Owner", "email" => "big@big.test", "approved" => 1, "company_name" => "Big Co"],
    ["id" => 71, "name" => "B1", "email" => "b1@big.test", "approved" => 1, "account_owner_id" => 70],
    ["id" => 72, "name" => "B2", "email" => "b2@big.test", "approved" => 1, "account_owner_id" => 70],
    ["id" => 73, "name" => "B3", "email" => "b3@big.test", "approved" => 1, "account_owner_id" => 70],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 3, "user_id" => 60], ["admin_id" => 4, "user_id" => 60]]);
seed($pdo, "client_admin_notes", [["user_id" => 50, "admin_id" => 3, "note" => "Target note"], ["user_id" => 60, "admin_id" => 3, "note" => "Source note"]]);
seed($pdo, "surveys", [["id" => 160, "title" => "Source form", "assigned_user_id" => 60, "status" => "completed", "created_by_admin_id" => 3]]);
seed($pdo, "survey_responses", [["id" => 260, "user_id" => 60, "survey_id" => 160, "survey_title" => "Source form"]]);
seed($pdo, "survey_uploaded_files", [["id" => 2600, "response_id" => 260, "question_id" => 1, "user_id" => 60, "original_name" => "s.txt", "stored_name" => "s.txt", "file_path" => $root . "/uploads/survey-files/60/260/s.txt"]]);
seed($pdo, "projects", [["id" => 560, "client_id" => 60, "title" => "Source site", "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 3, "created_by_admin_id" => 3]]);
seed($pdo, "appointments", [["id" => 460, "user_id" => 60, "title" => "Source", "topic" => "Source", "date" => day("+4"), "time" => "09:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 3]]);
seed($pdo, "content", [["id" => 360, "title" => "Source post", "client" => "Sigma Limited", "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 3]]);
seed($pdo, "source_files", [["id" => 900, "original_name" => "both.pdf", "file_path" => "x"]]);
seed($pdo, "user_source_files", [["file_id" => 900, "user_id" => 50], ["file_id" => 900, "user_id" => 60]]);
seed($pdo, "client_invites", [["account_id" => 60, "email" => "pending@source.test", "token_hash" => str_repeat("9", 64), "invited_by" => 60, "expires_at" => time() + 3600]]);

@mkdir($root . "/uploads/survey-files/60/260", 0777, true);
file_put_contents($root . "/uploads/survey-files/60/260/s.txt", "source");

[$status, $r] = accountAction($owner, 50, ["action" => "merge", "source_id" => 50]);
check("merge: not into itself", $status === 422, "HTTP $status " . json_encode($r));
[$status] = accountAction($owner, 50, ["action" => "merge", "source_id" => 61]);
check("merge: only an account, not a teammate", $status === 404 && (int) scalar($pdo, "SELECT account_owner_id FROM users WHERE id = 61") === 60, "HTTP $status");

// Big Co has 4 people, so with Sigma's 2 (Tess, Ned) it's 6, one too many.
[$status, $r] = accountAction($owner, 50, ["action" => "merge", "source_id" => 70]);
check("merge: over the limit is refused", $status === 409 && strpos($r["message"], "6 people") !== false, "HTTP $status " . json_encode($r));
check("merge: and changes nothing", scalar($pdo, "SELECT account_owner_id FROM users WHERE id = 70") === null
    && (int) scalar($pdo, "SELECT account_owner_id FROM users WHERE id = 71") === 70);

[$status, $r] = accountAction($owner, 50, ["action" => "merge_preview", "source_id" => 60]);
check("merge: the preview counts what moves, and changes nothing", $status === 200 && ($r["plan"]["counts"] ?? null) === ["forms" => 1, "projects" => 1, "meetings" => 1, "files" => 1, "people" => 2]
    && ($r["plan"]["names_differ"] ?? null) === true && (int) scalar($pdo, "SELECT client_id FROM projects WHERE id = 560") === 60, "HTTP $status " . json_encode($r));

[$status, $r] = accountAction($owner, 50, ["action" => "merge", "source_id" => 60]);
check("merge: the Director merges", $status === 200 && ($r["success"] ?? false) === true, "HTTP $status " . json_encode($r));

foreach ([
    "forms" => "SELECT assigned_user_id FROM surveys WHERE id = 160",
    "responses" => "SELECT user_id FROM survey_responses WHERE id = 260",
    "uploads" => "SELECT user_id FROM survey_uploaded_files WHERE id = 2600",
    "projects" => "SELECT client_id FROM projects WHERE id = 560",
    "meetings" => "SELECT user_id FROM appointments WHERE id = 460",
    "invites" => "SELECT account_id FROM client_invites WHERE email = 'pending@source.test'",
] as $what => $sql) {
    check("merge: $what move", (int) scalar($pdo, $sql) === 50);
}
check("merge: staff come along", (bool) one($pdo, "SELECT id FROM admin_user_assignments WHERE admin_id = 4 AND user_id = 50"));
check("merge: staff on both aren't doubled", (int) scalar($pdo, "SELECT COUNT(*) FROM admin_user_assignments WHERE admin_id = 3 AND user_id = 50") === 1
    && (int) scalar($pdo, "SELECT COUNT(*) FROM admin_user_assignments WHERE user_id = 60") === 0);
check("merge: a file assigned to both isn't doubled", (int) scalar($pdo, "SELECT COUNT(*) FROM user_source_files WHERE file_id = 900") === 1);
check("merge: a staff member's two notes are kept", scalar($pdo, "SELECT note FROM client_admin_notes WHERE admin_id = 3 AND user_id = 50") === "Target note\n\nSource note");
check("merge: posts follow to the target's name", scalar($pdo, "SELECT client FROM content WHERE id = 360") === "Sigma Ltd");

$sal = one($pdo, "SELECT account_owner_id, company_name FROM users WHERE id = 60");
check("merge: the old owner is a teammate, with no company of their own", (int) $sal["account_owner_id"] === 50 && $sal["company_name"] === null, json_encode($sal));
check("merge: and so are their teammates", (int) scalar($pdo, "SELECT account_owner_id FROM users WHERE id = 61") === 50);
check("merge: old responses keep their real author", scalar($pdo, "SELECT submitted_by_name FROM survey_responses WHERE id = 260") === "Sal Source");
check("merge: it's on the event log", (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'client.accounts_merged' AND client_id = 50"));

[, $r] = post("api/login.php", "", ["email" => "sal@source.test", "password" => "sal-password"]);
check("merge: the old owner signs in as a teammate", ($r["success"] ?? false) === true && ($r["user"]["is_account_owner"] ?? null) === false
    && ($r["user"]["company_name"] ?? "") === "Sigma Ltd", json_encode($r));
$salToken = $r["token"] ?? "";
[, $r] = pageGet("api/dashboard.php", $salToken);
check("merge: seeing both accounts' forms", in_array("Source form", surveyTitles($r), true) && in_array("Sigma brief", surveyTitles($r), true), json_encode(surveyTitles($r)));
$clients = ids(get("clients.php", $owner)[1]);
check("merge: staff see one client", in_array(50, $clients, true) && !in_array(60, $clients, true), json_encode($clients));

[$status, $r] = post("api/my-team.php", "tok-tess", ["action" => "invite", "email" => "more@sigma.test"]);
check("merge: the merged team can be at the limit", $status === 409, "HTTP $status " . json_encode($r));

// A merged account's uploads stayed in the old folder, and still go with it.
[, $r] = post("api/delete-client.php", $owner, ["user_id" => 50, "confirm_email" => "tess@sigma.test"]);
check("merge: deleting the account afterwards takes everyone", ($r["success"] ?? false) === true && !one($pdo, "SELECT id FROM users WHERE id IN (50, 52, 60, 61)"), json_encode($r));
check("merge: and the files from the old folder", !is_file($root . "/uploads/survey-files/60/260/s.txt"));
@rmdir($root . "/uploads/survey-files/60/260");
@rmdir($root . "/uploads/survey-files/60");

// ── Team tools over MCP ──────────────────────────────────────────────────

seed($pdo, "users", [
    ["id" => 80, "name" => "Ana Owner", "email" => "ana@tau.test", "approved" => 1, "session_token" => "tok-ana", "company_name" => "Tau Ltd"],
    ["id" => 81, "name" => "Max Mate", "email" => "max@tau.test", "approved" => 1, "session_token" => "tok-maxm", "account_owner_id" => 80],
    ["id" => 82, "name" => "Lee Mate", "email" => "lee@tau.test", "approved" => 1, "account_owner_id" => 80],
    ["id" => 83, "name" => "Kim Mate", "email" => "kim@tau.test", "approved" => 1, "account_owner_id" => 80],
]);

[$status, $r] = get("me/team.php", "tok-maxm");
check("mcp: my_team for a teammate", $status === 200 && count($r["data"]["people"] ?? []) === 4 && $r["data"]["can_manage"] === false && $r["data"]["invites"] === [], "HTTP $status " . json_encode($r));

refusedChange("mcp: a teammate can't invite", "tok-maxm", "invite_teammate", ["email" => "new@tau.test"], 403);
refusedChange("mcp: or remove", "tok-maxm", "remove_teammate", ["id" => 82], 403);
refusedChange("mcp: staff have no team to invite to", $owner, "invite_teammate", ["email" => "new@tau.test"], 404);
refusedChange("mcp: a bad address", "tok-ana", "invite_teammate", ["email" => "nope"], 422);
refusedChange("mcp: someone on another team", "tok-ana", "remove_teammate", ["id" => 11], 404);

[$status, $prep] = prepare("tok-ana", "invite_teammate", ["email" => "New@Tau.test"]);
check("mcp: an invite is only prepared", $status === 200 && !one($pdo, "SELECT id FROM client_invites WHERE email = 'new@tau.test'"), "HTTP $status " . json_encode($prep));
[$status, $done] = confirm("tok-ana", $prep);
$inviteId = (int) scalar($pdo, "SELECT id FROM client_invites WHERE email = 'new@tau.test'");
check("mcp: until it's confirmed", $status === 200 && ($done["data"]["done"] ?? false) === true && $inviteId > 0, "HTTP $status " . json_encode($done));

// Three teammates and that invite: the team is full, through either door.
[$status, $prep] = prepare("tok-ana", "invite_teammate", ["email" => "over@tau.test"]);
[$status, $done] = confirm("tok-ana", $prep);
check("mcp: the limit holds at confirm", $status === 409 && !one($pdo, "SELECT id FROM client_invites WHERE email = 'over@tau.test'"), "HTTP $status " . json_encode($done));

$pdo->prepare("UPDATE client_invites SET expires_at = ? WHERE id = ?")->execute([time() + 3600, $inviteId]);
change("mcp: resend", "tok-ana", "resend_team_invite", ["id" => $inviteId]);
change("mcp: revoke", "tok-ana", "revoke_team_invite", ["id" => $inviteId]);
check("mcp: revoked", scalar($pdo, "SELECT revoked_at FROM client_invites WHERE id = ?", [$inviteId]) !== null);
refusedChange("mcp: a revoked invite is closed", "tok-ana", "resend_team_invite", ["id" => $inviteId], 404);

[$status, $prep] = prepare("tok-ana", "remove_teammate", ["id" => 83]);
check("mcp: removing is only prepared", $status === 200 && one($pdo, "SELECT id FROM users WHERE id = 83"), "HTTP $status " . json_encode($prep));
[$status, $done] = confirm("tok-ana", $prep);
check("mcp: until it's confirmed, too", $status === 200 && !one($pdo, "SELECT id FROM users WHERE id = 83"), "HTTP $status " . json_encode($done));

