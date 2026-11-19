<?php

/*
| Activity reports and departments - required by run.php near the end, with its
| own admins so what earlier files changed doesn't matter.
|
|   1. Planning: reports.create for the team, one report per person per day;
|      the planner reviews it. Your own report goes to the reviewers you pick
|      (the Director, or reports.review), never yourself (audit F8).
|   2. The day: the author edits in place, the reviewer reviews Done work as
|      it lands; Needs changes reopens the activity.
|   3. Edit plan keeps work (F3), delete only before anything happened (F4).
|   4. Close, fix what was sent back, review the report.
|   5. A send-back on an earlier day can still be fixed (F1), and counts in
|      the badge (F11).
|   6. reports.view_all is viewing only (F6).
|   7. "Open Activity Reports" switched off refuses the endpoints (F2).
|   8. Templates belong to their maker and the Director (F13).
|   9. Departments: the Director's to change.
|  10. Notifications: who hears what, and reviews merge into one row.
*/

ensureDailyReportTables($pdo);

seed($pdo, "admins", [
    ["id" => 41, "name" => "Mark", "email" => "mark@w.test", "role" => "account_manager", "session_token" => "tok-dr-mark"],
    ["id" => 42, "name" => "Hana", "email" => "hana@w.test", "role" => "seo_admin", "session_token" => "tok-dr-hana", "managed_by_admin_id" => 41],
    ["id" => 43, "name" => "Dina", "email" => "dina@w.test", "role" => "seo_admin", "session_token" => "tok-dr-dina"],
    ["id" => 44, "name" => "Vera", "email" => "vera@w.test", "role" => "super_admin", "session_token" => "tok-dr-vera"],
]);
seed($pdo, "admin_permission_overrides", [["admin_id" => 44, "permission" => "reports.view_all", "allowed" => 1]]);
$mark = "tok-dr-mark"; $hana = "tok-dr-hana"; $dina = "tok-dr-dina"; $vera = "tok-dr-vera";

$drToday = (new DateTime("now", new DateTimeZone("UTC")))->format("Y-m-d");

function drPost($token, array $body)
{
    return post("api/activity-report-writes.php", $token, $body);
}

function drGet($token, $query)
{
    return pageGet("api/activity-reports.php?" . $query, $token);
}

/** Unread notifications of one type for one admin: [[title, body, link], ...]. */
function drNotes(PDO $pdo, $adminId, $type)
{
    $stmt = $pdo->prepare("SELECT title, body, link FROM notifications WHERE recipient_kind = 'admin' AND recipient_id = ? AND event_type = ? AND read_at IS NULL ORDER BY id");
    $stmt->execute([(int) $adminId, $type]);
    return $stmt->fetchAll(PDO::FETCH_NUM);
}

function drReport($token, $id)
{
    [, $r] = drGet($token, "action=get&id=" . (int) $id);
    return $r;
}

// ── 1. Planning ──────────────────────────────────────────────────────────

$acts = [
    ["title" => "Answer phone calls", "description" => "Main line", "count_unit" => "calls"],
    ["title" => "Process mail", "description" => ""],
    ["title" => "Book rooms", "description" => ""],
];

[$status, $r] = drPost($hana, ["action" => "plan", "admin_ids" => [43], "activities" => $acts]);
check("daily: an Admin can't plan for others", $status === 403 && !$r["success"], "HTTP $status " . json_encode($r));

[$status, $r] = drPost($mark, ["action" => "plan", "self" => true, "reviewer_ids" => [], "activities" => $acts]);
check("daily: your own report needs a reviewer", $status === 422 && strpos($r["message"] ?? "", "Pick who reviews it") !== false, json_encode($r));
[$status, $r] = drPost($mark, ["action" => "plan", "self" => true, "reviewer_ids" => [41], "activities" => $acts]);
check("daily: nobody reviews their own report", $status === 422 && strpos($r["message"] ?? "", "can't review your own") !== false, json_encode($r));
[$status, $r] = drPost($mark, ["action" => "plan", "self" => true, "reviewer_ids" => [43], "activities" => $acts]);
check("daily: reviewers must review team reports", $status === 422 && strpos($r["message"] ?? "", "can't review activity reports") !== false, json_encode($r));

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [42], "activities" => $acts]);
check("daily: the Director plans Hana's day", $status === 200 && $r["success"] && count($r["ids"] ?? []) === 1, json_encode($r));
$hanaReport = (int) ($r["ids"][0] ?? 0);
check("daily: ...and reviews it herself", array_column(drReport($owner, $hanaReport)["report"]["reviewers"] ?? [], "id") === [1],
    json_encode(drReport($owner, $hanaReport)["report"]["reviewers"] ?? null));

$n = drNotes($pdo, 42, "daily_planned");
check("notify: Hana hears her day was planned", count($n) === 1 && strpos($n[0][0], "Olivia planned your day") === 0
    && $n[0][2] === "activity-report-details.html?id=$hanaReport", json_encode($n));

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [42], "activities" => $acts]);
check("daily: one report per person per day", $status === 409, "HTTP $status " . json_encode($r));

[$status, $r] = drGet($hana, "action=pending_count");
check("daily: Hana's badge shows today's report", $status === 200 && $r["pending"] === 1 && $r["today"] === $drToday, json_encode($r));

[$status, $r] = drGet($hana, "action=mine");
check("daily: Hana sees it as today's", ($r["today_sheet"]["id"] ?? 0) === $hanaReport && $r["today_sheet"]["effective_status"] === "planned", json_encode($r));

[$status, $r] = drGet($dina, "action=get&id=$hanaReport");
check("daily: someone else's report is a 404", $status === 404, "HTTP $status");

// ── 2. The day ───────────────────────────────────────────────────────────

$rep = drReport($hana, $hanaReport);
$a = array_column($rep["report"]["activities"] ?? [], "id");
check("daily: Hana may fill it in", $rep["can_fill"] === true && $rep["can_fix"] === false && count($a) === 3, json_encode($rep));

[$status, $r] = drPost($hana, ["action" => "update_activities", "id" => $hanaReport, "activities" => [
    ["id" => $a[0], "status" => "done", "outcome" => "31 calls", "count_value" => 31],
    ["id" => $a[1], "status" => "done", "outcome" => "Scanned", "count_value" => null],
]]);
check("daily: Hana saves two activities as Done", $status === 200 && $r["success"], json_encode($r));

[$status, $r] = drPost($hana, ["action" => "update_activities", "id" => $hanaReport, "activities" => [
    ["id" => $a[2], "status" => "done", "outcome" => "", "count_value" => -1],
]]);
check("daily: counts are whole numbers", $status === 422, json_encode($r));

$rep = drReport($mark, $hanaReport);
check("daily: Mark (her reviewer) sees it awaiting review", ($rep["report"]["effective_status"] ?? "") === "awaiting"
    && $rep["can_review_activities"] === true && $rep["can_review"] === false, json_encode($rep));

[$status, $r] = drPost($mark, ["action" => "review_activity", "activity_id" => $a[1], "state" => "changes", "feedback" => ""]);
check("daily: Needs changes needs feedback", $status === 422, json_encode($r));

[$status, $r] = drPost($mark, ["action" => "review_activity", "activity_id" => $a[1], "state" => "changes", "feedback" => "Say what was scanned."]);
check("daily: Mark sends one back", $status === 200 && $r["success"], json_encode($r));

[$status, $r] = drPost($mark, ["action" => "review_activity", "activity_id" => $a[0], "state" => "approved", "feedback" => ""]);
check("daily: Mark approves the other", $status === 200 && $r["success"], json_encode($r));

$n = drNotes($pdo, 42, "daily_activity_reviewed");
check("notify: two reviews are one merged row", count($n) === 1 && strpos($n[0][0], "Mark asked for changes") === 0
    && strpos($n[0][1], "1 reviewed, 1 needs changes") !== false, json_encode($n));

$rep = drReport($hana, $hanaReport);
$byId = array_column($rep["report"]["activities"], null, "id");
check("daily: the sent-back one reopens", $byId[$a[1]]["status"] === "in_progress" && $byId[$a[1]]["review"]["state"] === "changes", json_encode($byId[$a[1]]));

[$status, $r] = drPost($hana, ["action" => "update_activities", "id" => $hanaReport, "activities" => [
    ["id" => $a[0], "status" => "in_progress", "outcome" => "", "count_value" => null],
]]);
check("daily: a reviewed activity is locked", $status === 422 && strpos($r["message"] ?? "", "has been reviewed") !== false, json_encode($r));

// ── 3. Edit plan and delete ──────────────────────────────────────────────

$plan = array_map(function ($x) {
    return ["id" => $x["id"], "title" => $x["title"], "description" => $x["description"], "count_unit" => $x["count_unit"]];
}, $rep["report"]["activities"]);
check("daily: worked-on activities are locked in the plan", $byId[$a[0]]["plan_locked"] === true && $byId[$a[2]]["plan_locked"] === false, json_encode($byId));

$renamed = $plan;
$renamed[0]["title"] = "Calls (renamed)";
[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "activities" => $renamed]);
check("daily: Edit plan can't retitle worked-on work (F3)", $status === 422 && strpos($r["message"] ?? "", "its title can't change") !== false, json_encode($r));

[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "activities" => array_slice($plan, 1)]);
check("daily: nor remove it", $status === 422 && strpos($r["message"] ?? "", "can't be removed") !== false, json_encode($r));

$edited = $plan;
$edited[2]["title"] = "Book meeting rooms";
$edited[] = ["id" => 0, "title" => "Order supplies", "description" => "", "count_unit" => null];
[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "activities" => $edited]);
check("daily: untouched ones change and new ones are added", $status === 200 && $r["success"], json_encode($r));
$rep = drReport($hana, $hanaReport);
$titles = array_column($rep["report"]["activities"], "title");
$kept = array_column($rep["report"]["activities"], null, "id")[$a[0]] ?? [];
check("daily: ...and the work done stays", $titles === ["Answer phone calls", "Process mail", "Book meeting rooms", "Order supplies"]
    && $kept["outcome"] === "31 calls" && $kept["count_value"] === 31 && $kept["review"]["state"] === "approved", json_encode($rep["report"]["activities"]));

[$status, $r] = drPost($owner, ["action" => "delete", "id" => $hanaReport]);
check("daily: a started report can't be deleted (F4)", $status === 422, json_encode($r));

// ── 4. Close, fix, review ────────────────────────────────────────────────

[$status, $r] = drPost($hana, ["action" => "close", "id" => $hanaReport]);
check("daily: Hana closes the day", $status === 200 && $r["success"], json_encode($r));

$n = drNotes($pdo, 1, "daily_report");
check("notify: her reviewer (the Director, who planned it) hears she closed it", count($n) === 1 && strpos($n[0][0], "Hana closed their activity report") === 0, json_encode($n));

$rep = drReport($hana, $hanaReport);
check("daily: closed, she may only fix what was sent back", $rep["can_fill"] === false && $rep["can_fix"] === true, json_encode($rep));
$all = array_column($rep["report"]["activities"], "id");

[$status, $r] = drPost($hana, ["action" => "update_activities", "id" => $hanaReport, "activities" => [
    ["id" => $all[2], "status" => "done", "outcome" => "", "count_value" => null],
]]);
check("daily: anything else is locked", $status === 422 && strpos($r["message"] ?? "", "Only activities sent back") !== false, json_encode($r));

$rep = drReport($mark, $hanaReport);
[$status, $r] = drPost($mark, ["action" => "review", "id" => $hanaReport, "feedback" => ""]);
check("daily: the report can't be reviewed while work waits on changes", $rep["review_blocked"] === true && $status === 422, json_encode($r));

[$status, $r] = drPost($hana, ["action" => "update_activities", "id" => $hanaReport, "activities" => [
    ["id" => $a[1], "status" => "done", "outcome" => "Scanned the Acme contracts", "count_value" => null],
]]);
check("daily: Hana fixes the sent-back one", $status === 200 && $r["success"], json_encode($r));

$n = drNotes($pdo, 1, "daily_report");
check("notify: ...and, in its place, that she fixed what he sent back", count($n) === 1 && strpos($n[0][0], "Hana updated what you sent back") === 0, json_encode($n));

[$status, $r] = drPost($mark, ["action" => "review", "id" => $hanaReport, "feedback" => "Thanks"]);
check("daily: Mark reviews the report", $status === 200 && $r["success"] && strpos($r["message"], "1 more activity") !== false, json_encode($r));
$rep = drReport($hana, $hanaReport);
check("daily: reviewed, and the earlier send-back is in the history", $rep["report"]["effective_status"] === "reviewed"
    && count(array_column($rep["report"]["activities"], null, "id")[$a[1]]["history"]) === 1, json_encode($rep["report"]));

check("notify: the report's review replaces the activity row", drNotes($pdo, 42, "daily_activity_reviewed") === []
    && (drNotes($pdo, 42, "daily_report_reviewed")[0][1] ?? "") === "Thanks", json_encode(drNotes($pdo, 42, "daily_report_reviewed")));

// ── 5. A send-back on an earlier day (F1, F11) ───────────────────────────

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [43], "activities" => [["title" => "Screen CVs", "count_unit" => "CVs"]]]);
$dinaReport = (int) ($r["ids"][0] ?? 0);
$da = array_column(drReport($dina, $dinaReport)["report"]["activities"], "id");
drPost($dina, ["action" => "update_activities", "id" => $dinaReport, "activities" => [["id" => $da[0], "status" => "done", "outcome" => "12", "count_value" => 12]]]);
drPost($owner, ["action" => "review_activity", "activity_id" => $da[0], "state" => "changes", "feedback" => "Which role?"]);
$pdo->prepare("UPDATE daily_reports SET report_date = ? WHERE id = ?")->execute([day(-1), $dinaReport]);

$rep = drReport($dina, $dinaReport);
check("daily: yesterday's sent-back work can still be fixed (F1)", $rep["can_fix"] === true && $rep["report"]["effective_status"] === "missing", json_encode($rep));
[, $r] = drGet($dina, "action=pending_count");
check("daily: and it counts in Dina's badge (F11)", ($r["pending"] ?? 0) === 1, json_encode($r));
[$status, $r] = drPost($dina, ["action" => "update_activities", "id" => $dinaReport, "activities" => [["id" => $da[0], "status" => "done", "outcome" => "12, for the designer role", "count_value" => 12]]]);
check("daily: Dina fixes it", $status === 200 && $r["success"], json_encode($r));
[$status, $r] = drPost($owner, ["action" => "delete", "id" => $dinaReport]);
check("daily: a missed report stays on record (F4)", $status === 422 && strpos($r["message"] ?? "", "stays on record") !== false, json_encode($r));

// ── 6. view_all is viewing only (F6) ─────────────────────────────────────

$rep = drReport($vera, $dinaReport);
check("daily: Vera (view_all) sees Dina's report", ($rep["report"]["id"] ?? 0) === $dinaReport && $rep["can_review_activities"] === false, json_encode($rep));
[$status, $r] = drPost($vera, ["action" => "review_activity", "activity_id" => $da[0], "state" => "approved", "feedback" => ""]);
check("daily: but can't review it (F6)", $status === 403, "HTTP $status " . json_encode($r));

[$status, $r] = drGet($owner, "action=list&from=" . day(-1) . "&to=$drToday&limit=1");
check("daily: Team reports pages by cursor", $status === 200 && count($r["items"]) === 1 && $r["next_cursor"] === "1" && $r["total"] === 2, json_encode($r));
[$status, $r] = drGet($owner, "action=list&from=" . day(-1) . "&to=$drToday&status=reviewed");
check("daily: and filters by status", array_column($r["items"] ?? [], "id") === [$hanaReport], json_encode($r));

// ── 7. "Open Activity Reports" off (F2) ─────────────────────────────────────

seed($pdo, "role_permissions", [["role" => "seo_admin", "permission" => "pages.daily_reports", "allowed" => 0]]);
[$status, $r] = drGet($dina, "action=pending_count");
check("daily: the page switched off refuses the endpoint (F2)", $status === 403 && $r["success"] === false, "HTTP $status " . json_encode($r));
[$status] = drPost($dina, ["action" => "close", "id" => $dinaReport]);
check("daily: ...writes too", $status === 403, "HTTP $status");
[$status] = drGet($owner, "action=pending_count");
check("daily: the Director is never switched off", $status === 200, "HTTP $status");
$pdo->exec("DELETE FROM role_permissions WHERE role = 'seo_admin' AND permission = 'pages.daily_reports'");

// ── 8. Templates (F13) ───────────────────────────────────────────────────

[$status, $r] = drPost($mark, ["action" => "template_save", "name" => "Mine", "activities" => $acts]);
check("daily: templates need reports.create", $status === 403, "HTTP $status");
$pdo->exec("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (41, 'reports.create', 1)");
[$status, $r] = drPost($owner, ["action" => "template_save", "name" => "Secretary daily", "activities" => $acts]);
$tpl = (int) ($r["id"] ?? 0);
check("daily: the Director saves a template", $status === 200 && $tpl > 0, json_encode($r));
[$status, $r] = drPost($mark, ["action" => "template_delete", "id" => $tpl]);
check("daily: only its maker or the Director changes it (F13)", $status === 403, "HTTP $status " . json_encode($r));
[$status, $r] = drGet($mark, "action=templates");
check("daily: ...but Mark can still use it", $status === 200 && ($r["templates"][0]["can_edit"] ?? null) === false, json_encode($r));

// ── 10. Withdrawn ────────────────────────────────────────────────────────

[, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [44], "activities" => $acts]);
$veraReport = (int) ($r["ids"][0] ?? 0);
[$status, $r] = drPost($owner, ["action" => "delete", "id" => $veraReport]);
$n = drNotes($pdo, 44, "daily_report_deleted");
check("notify: a withdrawn report is said, and its planned row goes", $status === 200 && count($n) === 1
    && drNotes($pdo, 44, "daily_planned") === [], json_encode([$r, $n]));

// ── 11. The Dashboard's cards ────────────────────────────────────────────

[$status, $r] = drGet($owner, "action=summary");
$sum = $r["summary"] ?? [];
check("summary: the Director gets today's team reports by status", $status === 200 && ($sum["team"]["reviewed"] ?? null) === 1
    && array_sum($sum["team"] ?? []) === 1 && $sum["my_report"] === null, json_encode($r));
[$status, $r] = drGet($hana, "action=summary");
$sum = $r["summary"] ?? [];
check("summary: Hana gets her own report and no team numbers", ($sum["my_report"]["id"] ?? 0) === $hanaReport
    && $sum["my_report"]["effective_status"] === "reviewed" && $sum["team"] === null && $sum["pending"] === 0, json_encode($r));
[, $r] = drGet($owner, "action=summary&department=none");
check("summary: one department at a time", array_sum($r["summary"]["team"] ?? [9]) === 1, json_encode($r));

// ── 12. Dashboard: My tasks | Team tasks ─────────────────────────────────

[$status, $r] = pageGet("api/admin-dashboard.php", $owner);
check("dashboard: the Director's Team tasks cover everyone", $status === 200 && ($r["team"]["scope"] ?? "") === "all"
    && is_array($r["team"]["attention"] ?? null) && is_array($r["tasks"] ?? null), "HTTP $status " . json_encode(array_keys($r ?? [])));
[$status, $r] = pageGet("api/admin-dashboard.php", $mark);
check("dashboard: a manager with a team gets their team's tasks", $status === 200 && ($r["team"]["scope"] ?? "") === "my_team"
    && array_key_exists("attention", $r["team"] ?? []) && $r["team"]["attention"] === null, "HTTP $status " . json_encode($r["team"] ?? null));
[$status, $r] = pageGet("api/admin-dashboard.php", $dina);
check("dashboard: no team, no Team tasks", $status === 200 && !array_key_exists("team", $r ?? []), "HTTP $status");

// ── 13. Through the AI connection (api/v1) ───────────────────────────────

[, $r] = drPost($mark, ["action" => "plan", "self" => true, "reviewer_ids" => [1, 44], "activities" => [
    ["title" => "Northwind proposal", "description" => "Scope and pricing"],
    ["title" => "Client calls", "count_unit" => "calls"],
]]);
$markReport = (int) ($r["ids"][0] ?? 0);
check("v1 daily: Mark plans his own day", $markReport > 0, json_encode($r));
check("daily: his own report goes to both his picks", array_column(drReport($mark, $markReport)["report"]["reviewers"] ?? [], "id") === [1, 44],
    json_encode(drReport($mark, $markReport)["report"]["reviewers"] ?? null));
$rep = drReport($vera, $markReport);
check("daily: a picked reviewer sees it and may review it", ($rep["report"]["id"] ?? 0) === $markReport && $rep["can_review_activities"] === true, json_encode($rep));

[$status, $r] = drPost($mark, ["action" => "end_of_day", "id" => $markReport, "outstanding" => "", "priorities" => "Northwind call", "challenges" => "No pricing yet"]);
check("daily: a challenge needs how urgent it is", $status === 422 && strpos($r["message"] ?? "", "how urgent") !== false, json_encode($r));
[$status, $r] = drPost($mark, ["action" => "end_of_day", "id" => $markReport, "outstanding" => "Half the proposal", "priorities" => "Northwind call",
    "challenges" => "No pricing yet", "challenge_priority" => "high"]);
$rep = drReport($mark, $markReport)["report"] ?? [];
check("daily: the end-of-day note is saved", $status === 200 && $rep["outstanding"] === "Half the proposal" && $rep["priorities"] === "Northwind call"
    && $rep["challenges"] === "No pricing yet" && $rep["challenge_priority"] === "high", json_encode($rep));
[$status, $r] = drPost($vera, ["action" => "end_of_day", "id" => $markReport, "outstanding" => "x"]);
check("daily: only the author writes it", $status === 403, "HTTP $status " . json_encode($r));

[$status, $r] = get("activity-reports.php", $mark);
$fixtures["list_my_activity_reports"] = $r["data"] ?? null;
check("v1 daily: list_my_activity_reports has today's", $status === 200 && ($r["data"]["today_report"]["id"] ?? 0) === $markReport, "HTTP $status " . json_encode($r));

[$status, $r] = get("activity-reports.php?id=$markReport", $mark);
$ma = array_column($r["data"]["activities"] ?? [], "id");
check("v1 daily: get_activity_report, typed text under untrusted_content", $status === 200 && count($ma) === 2
    && ($r["data"]["activities"][0]["untrusted_content"]["title"] ?? "") === "Northwind proposal" && $r["data"]["can_edit"] === true, json_encode($r));

[$status] = get("activity-reports.php?id=$markReport", $dina);
check("v1 daily: someone else's report is a 404", $status === 404, "HTTP $status");

change("v1 daily: Mark saves the calls as Done", $mark, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[1], "status" => "done", "outcome" => "Called 6 leads", "count" => 6]);
refusedChange("v1 daily: nothing changes, nothing to prepare", $mark, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[1], "status" => "done"], 409);
refusedChange("v1 daily: nor on someone else's report", $dina, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[0], "status" => "done"], 404);
refusedChange("v1 daily: a count needs a counted activity", $mark, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[0], "count" => 3], 422);
change("v1 daily: Mark closes the day", $mark, "close_activity_report", ["id" => $markReport, "outstanding" => "Proposal pricing"]);
$n1 = drNotes($pdo, 1, "daily_report"); $n44 = drNotes($pdo, 44, "daily_report");
$mine = function ($n) use ($markReport) { return array_values(array_filter($n, function ($x) use ($markReport) { return $x[2] === "activity-report-details.html?id=$markReport"; })); };
check("notify: both reviewers hear he closed it, and that he needs help", count($mine($n1)) === 1 && count($mine($n44)) === 1
    && strpos($mine($n44)[0][1] ?? "", "Needs help") !== false, json_encode([$n1, $n44]));
check("daily: closing kept the rest of the note", (drReport($mark, $markReport)["report"]["priorities"] ?? "") === "Northwind call"
    && drReport($mark, $markReport)["report"]["outstanding"] === "Proposal pricing", json_encode(drReport($mark, $markReport)["report"] ?? null));

refusedChange("v1 daily: an Admin has no review scope", $dina, "review_report_activity",
    ["activity" => $ma[1], "decision" => "approved"], 403);
refusedChange("v1 daily: sending back needs feedback", $owner, "review_report_activity",
    ["activity" => $ma[1], "decision" => "changes"], 422);
change("v1 daily: the Director sends it back", $owner, "review_report_activity",
    ["activity" => $ma[1], "decision" => "changes", "feedback" => "Which leads?"]);
refusedChange("v1 daily: the report can't be reviewed while work waits", $owner, "review_activity_report", ["id" => $markReport], 409);
refusedChange("v1 daily: closed, only sent-back work changes", $mark, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[0], "status" => "done"], 422);
change("v1 daily: Mark fixes it", $mark, "update_report_activity",
    ["report" => $markReport, "activity" => $ma[1], "status" => "done", "outcome" => "Called 6 leads from the Acme list"]);

[$status, $r] = get("activity-reports.php?team=1&from=" . day(-1) . "&to=$drToday", $owner);
$fixtures["list_team_activity_reports"] = $r["data"] ?? null;
check("v1 daily: list_team_activity_reports, awaiting first", $status === 200 && ($r["data"]["items"][0]["id"] ?? 0) === $markReport
    && ($r["data"]["items"][0]["status"] ?? "") === "awaiting", json_encode($r));
[$status] = get("activity-reports.php?team=1", $dina);
check("v1 daily: an Admin has no Team reports", $status === 403, "HTTP $status");

change("v1 daily: the Director reviews the report", $owner, "review_activity_report", ["id" => $markReport, "note" => "Good day"]);
check("notify: one reviewer completes it, and the other's \"ready\" row goes", count($mine(drNotes($pdo, 44, "daily_report"))) === 0,
    json_encode(drNotes($pdo, 44, "daily_report")));
[$status, $r] = drPost($vera, ["action" => "review", "id" => $markReport, "feedback" => ""]);
check("daily: ...and can't be reviewed twice", $status !== 200, "HTTP $status " . json_encode($r));
[$status, $r] = get("activity-reports.php?id=$markReport", $mark);
$fixtures["get_activity_report"] = $r["data"] ?? null;
check("v1 daily: reviewed, with the history of the send-back", ($r["data"]["status"] ?? "") === "reviewed"
    && ($r["data"]["untrusted_content"]["reviewer_note"] ?? "") === "Good day"
    && ($r["data"]["activities"][1]["review"]["state"] ?? "") === "approved", json_encode($r));

// ── 9. Departments ───────────────────────────────────────────────────────

[$status, $r] = post("api/departments.php", $mark, ["action" => "create", "name" => "Sales"]);
check("departments: only the Director changes them", $status === 403, "HTTP $status");
[$status, $r] = post("api/departments.php", $owner, ["action" => "create", "name" => "Sales"]);
check("departments: the Director adds one", $status === 200 && ($r["departments"][0]["name"] ?? "") === "Sales", json_encode($r));
[$status, $r] = post("api/departments.php", $owner, ["action" => "create", "name" => "sales"]);
check("departments: names are unique", $status === 409, "HTTP $status");
[$status, $r] = pageGet("api/departments.php", $hana);
check("departments: anyone can list them", $status === 200 && count($r["departments"] ?? []) === 1, json_encode($r));
