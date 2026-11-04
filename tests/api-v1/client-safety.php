<?php

/*
| Client safety - required by run.php after clients.php, sharing its scope.
|
| The company name is staff's to change, because it decides which posts and
| files an account can see; a deleted client leaves nothing of theirs behind;
| and nobody creates an account without going through registration.
*/

// ── No way in around registration ────────────────────────────────────────

[$status] = post("api/add-normal-user.php", "", ["name" => "X", "email" => "x@x.test", "password" => "secret123"]);
check("safety: the open sign-up endpoint is gone", $status === 404 && !one($pdo, "SELECT id FROM users WHERE email = 'x@x.test'"), "HTTP $status");

// ── The company name ─────────────────────────────────────────────────────

seed($pdo, "users", [
    ["id" => 20, "name" => "Cara", "email" => "cara@gamma.test", "approved" => 1, "session_token" => "tok-cara", "company_name" => "Gamma Co"],
    ["id" => 21, "name" => "Dan", "email" => "dan@delta.test", "approved" => 1, "session_token" => "tok-dan", "company_name" => "Delta Co"],
    ["id" => 22, "name" => "Dana", "email" => "dana@delta.test", "approved" => 1, "company_name" => "Delta Co"],
    ["id" => 23, "name" => "Eve", "email" => "eve@x.test", "approved" => 0, "company_name" => "Gamma Co"],
]);
seed($pdo, "content", [
    ["id" => 320, "title" => "Gamma post", "client" => "Gamma Co", "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 3],
    ["id" => 321, "title" => "Delta post", "client" => "Delta Co", "content_type" => "articles", "status" => "published", "publish_now" => 1, "created_by" => 3],
]);

[, $r] = post("api/update-profile.php", "tok-cara", ["name" => "Cara", "company_name" => "Delta Co", "website" => "gamma.test"], [], true);
$row = one($pdo, "SELECT company_name, website FROM users WHERE id = 20");
check("safety: a client can't change their company name", ($r["success"] ?? false) === true
    && $row["company_name"] === "Gamma Co" && $row["website"] === "https://gamma.test", json_encode([$r, $row]));
check("safety: the reply shows the name that was kept", ($r["user"]["company_name"] ?? null) === "Gamma Co", json_encode($r));

[, $r] = pageGet("api/user-content.php", "tok-cara");
$titles = array_column($r["content"] ?? $r["data"] ?? [], "title");
check("safety: so another company's post stays out of reach", !in_array("Delta post", $titles, true), json_encode($titles));

[, $r] = post("api/admin-user-details.php", $owner, ["action" => "save_details", "id" => 20, "company_name" => "Gamma Group"]);
check("safety: staff rename a client", ($r["success"] ?? false) === true
    && one($pdo, "SELECT company_name FROM users WHERE id = 20")["company_name"] === "Gamma Group", json_encode($r));
check("safety: their posts go with them", one($pdo, "SELECT client FROM content WHERE id = 320")["client"] === "Gamma Group");

[, $r] = post("api/admin-user-details.php", $owner, ["action" => "save_details", "id" => 21, "company_name" => "Dan Ltd"]);
check("safety: a shared name keeps its posts for the other account", ($r["success"] ?? false) === true
    && one($pdo, "SELECT client FROM content WHERE id = 321")["client"] === "Delta Co", json_encode($r));

[, $r] = post("api/admin-user-details.php", $owner, ["action" => "save_details", "id" => 22, "company_name" => ""]);
check("safety: clearing a name leaves posts alone", ($r["success"] ?? false) === true
    && one($pdo, "SELECT client FROM content WHERE id = 321")["client"] === "Delta Co", json_encode($r));

// A sign-up typing an approved account's company is flagged before approval.
$pdo->exec("UPDATE users SET company_name = 'Gamma Group' WHERE id = 23");
[, $r] = pageGet("api/admin-user-details.php?id=23", $owner);
check("safety: a sign-up matching an approved company is flagged", ($r["user"]["company_matches"] ?? null) === 1, json_encode($r["user"] ?? $r));
[, $r] = pageGet("api/admin-user-details.php?id=20", $owner);
check("safety: an approved account isn't", ($r["user"]["company_matches"] ?? null) === 0, json_encode($r["user"] ?? $r));

// ── Deleting a client ────────────────────────────────────────────────────

seed($pdo, "surveys", [["id" => 130, "title" => "Cara's form", "assigned_user_id" => 20, "status" => "completed", "created_by_admin_id" => 3]]);
seed($pdo, "survey_questions", [["id" => 1300, "survey_id" => 130, "question_text" => "Q", "question_type" => "input", "sort_order" => 1, "chips" => "[]"]]);
seed($pdo, "survey_responses", [["id" => 230, "user_id" => 20, "survey_id" => 130, "survey_title" => "Cara's form"]]);
seed($pdo, "survey_answers", [["id" => 2300, "response_id" => 230, "question_id" => 1300, "question_label" => "Q", "answer" => "A"]]);
seed($pdo, "survey_uploaded_files", [["id" => 2301, "response_id" => 230, "question_id" => 1300, "user_id" => 20, "original_name" => "a.txt", "stored_name" => "a.txt", "file_path" => "x"]]);
seed($pdo, "appointments", [["id" => 430, "user_id" => 20, "title" => "Cara", "topic" => "Cara", "date" => day("+4"), "time" => "15:00:00", "status" => "pending", "requested_by" => "admin", "admin_id" => 3]]);
seed($pdo, "projects", [["id" => 530, "client_id" => 20, "title" => "Cara's site", "start_date" => day("-1"), "end_date" => day("+9"), "account_manager_admin_id" => 3, "created_by_admin_id" => 3]]);
seed($pdo, "project_tasks", [["id" => 630, "project_id" => 530, "title" => "Phase", "status" => "published", "created_by_admin_id" => 3]]);
seed($pdo, "project_members", [["project_id" => 530, "admin_id" => 5]]);
seed($pdo, "admin_user_assignments", [["admin_id" => 3, "user_id" => 20]]);
seed($pdo, "client_admin_notes", [["user_id" => 20, "admin_id" => 3, "note" => "About Cara"]]);
seed($pdo, "notifications", [
    ["id" => 1960, "recipient_kind" => "user", "recipient_id" => 20, "event_type" => "x", "title" => "For Cara"],
    ["id" => 1961, "recipient_kind" => "admin", "recipient_id" => 3, "actor_kind" => "user", "actor_id" => 20, "event_type" => "x", "title" => "From Cara"],
    // A different person with the same number must survive.
    ["id" => 1962, "recipient_kind" => "admin", "recipient_id" => 20, "event_type" => "x", "title" => "For admin 20"],
]);
$caraToken = "wzat_" . str_repeat("d", 43);
seed($pdo, "mcp_grants", [["id" => 30, "client_id" => "c1", "principal_kind" => "user", "principal_id" => 20, "scopes" => "self:read", "resource" => "https://mcp.test/mcp"]]);
seed($pdo, "mcp_tokens", [["token_hash" => tokenHash($caraToken), "kind" => "access", "grant_id" => 30, "expires_at" => time() + 3600]]);

$caraFolder = $root . "/uploads/survey-files/20/230";
@mkdir($caraFolder, 0777, true);
file_put_contents($caraFolder . "/a.txt", "cara");
$bobFolder = $root . "/uploads/survey-files/11/999";
@mkdir($bobFolder, 0777, true);
file_put_contents($bobFolder . "/b.txt", "bob");

[, $r] = post("api/delete-client.php", $owner, ["user_id" => 20, "confirm_email" => "wrong@x.test"]);
check("safety: a stale confirmation deletes nothing", ($r["success"] ?? true) === false && one($pdo, "SELECT id FROM users WHERE id = 20"), json_encode($r));

[, $r] = post("api/delete-client.php", $am, ["user_id" => 20, "confirm_email" => "cara@gamma.test"]);
check("safety: only the Director deletes a client", ($r["success"] ?? true) === false && one($pdo, "SELECT id FROM users WHERE id = 20"), json_encode($r));

[, $r] = post("api/delete-client.php", $owner, ["user_id" => 20, "confirm_email" => "cara@gamma.test"]);
check("safety: the client is deleted", ($r["success"] ?? false) === true && !one($pdo, "SELECT id FROM users WHERE id = 20"), json_encode($r));

foreach (CLIENT_TENANT_COLUMNS as $table => $column) {
    if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch()) {
        check("safety: nothing left in $table", one($pdo, "SELECT COUNT(*) AS n FROM `$table` WHERE `$column` = 20")["n"] == 0);
    }
}
foreach ([
    "questions" => "SELECT COUNT(*) AS n FROM survey_questions WHERE survey_id = 130",
    "answers" => "SELECT COUNT(*) AS n FROM survey_answers WHERE response_id = 230",
    "phases" => "SELECT COUNT(*) AS n FROM project_tasks WHERE project_id = 530",
    "project members" => "SELECT COUNT(*) AS n FROM project_members WHERE project_id = 530",
    "notifications to them" => "SELECT COUNT(*) AS n FROM notifications WHERE recipient_kind = 'user' AND recipient_id = 20",
    "notifications from them" => "SELECT COUNT(*) AS n FROM notifications WHERE actor_kind = 'user' AND actor_id = 20",
    "AI connections" => "SELECT COUNT(*) AS n FROM mcp_grants WHERE principal_kind = 'user' AND principal_id = 20",
    "AI tokens" => "SELECT COUNT(*) AS n FROM mcp_tokens WHERE grant_id = 30",
] as $what => $sql) {
    check("safety: nothing left of their $what", one($pdo, $sql)["n"] == 0);
}

check("safety: their files leave the disk", !is_file($caraFolder . "/a.txt") && !is_dir($root . "/uploads/survey-files/20"));
check("safety: another client's files stay", is_file($bobFolder . "/b.txt"));
check("safety: an admin sharing their number keeps their notification", (bool) one($pdo, "SELECT id FROM notifications WHERE id = 1962"));
check("safety: the event log keeps the record", (bool) one($pdo, "SELECT id FROM admin_events WHERE action = 'client.deleted' AND target_id = 20"));

@unlink($bobFolder . "/b.txt");
@rmdir($bobFolder);
@rmdir(dirname($bobFolder));
