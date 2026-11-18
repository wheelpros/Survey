<?php

/*
| Daily reports and departments - required by run.php near the end, with its
| own admins so what earlier files changed doesn't matter.
|
|   1. Planning: reports.create for the team, one report per person per day,
|      nobody reviews their own report (audit F8).
|   2. The day: the author edits in place, the reviewer reviews Done work as
|      it lands; Needs changes reopens the activity.
|   3. Edit plan keeps work (F3), delete only before anything happened (F4).
|   4. Close, fix what was sent back, review the report.
|   5. A send-back on an earlier day can still be fixed (F1), and counts in
|      the badge (F11).
|   6. reports.view_all is viewing only (F6).
|   7. "Open Daily Reports" switched off refuses the endpoints (F2).
|   8. Templates belong to their maker and the Director (F13).
|   9. Departments: the Director's to change.
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
    return post("api/daily-report-writes.php", $token, $body);
}

function drGet($token, $query)
{
    return pageGet("api/daily-reports.php?" . $query, $token);
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

[$status, $r] = drPost($hana, ["action" => "plan", "admin_ids" => [43], "reviewer_id" => 1, "activities" => $acts]);
check("daily: an Admin can't plan for others", $status === 403 && !$r["success"], "HTTP $status " . json_encode($r));

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [41], "reviewer_id" => 41, "activities" => $acts]);
check("daily: nobody reviews their own report", $status === 422 && strpos($r["message"] ?? "", "can't review their own") !== false, json_encode($r));

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [42], "reviewer_id" => 41, "activities" => $acts]);
check("daily: the Director plans Hana's day", $status === 200 && $r["success"] && count($r["ids"] ?? []) === 1, json_encode($r));
$hanaReport = (int) ($r["ids"][0] ?? 0);

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [42], "reviewer_id" => 41, "activities" => $acts]);
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
[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "reviewer_id" => 41, "activities" => $renamed]);
check("daily: Edit plan can't retitle worked-on work (F3)", $status === 422 && strpos($r["message"] ?? "", "its title can't change") !== false, json_encode($r));

[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "reviewer_id" => 41, "activities" => array_slice($plan, 1)]);
check("daily: nor remove it", $status === 422 && strpos($r["message"] ?? "", "can't be removed") !== false, json_encode($r));

$edited = $plan;
$edited[2]["title"] = "Book meeting rooms";
$edited[] = ["id" => 0, "title" => "Order supplies", "description" => "", "count_unit" => null];
[$status, $r] = drPost($owner, ["action" => "plan", "id" => $hanaReport, "reviewer_id" => 41, "activities" => $edited]);
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

[$status, $r] = drPost($mark, ["action" => "review", "id" => $hanaReport, "feedback" => "Thanks"]);
check("daily: Mark reviews the report", $status === 200 && $r["success"] && strpos($r["message"], "1 more activity") !== false, json_encode($r));
$rep = drReport($hana, $hanaReport);
check("daily: reviewed, and the earlier send-back is in the history", $rep["report"]["effective_status"] === "reviewed"
    && count(array_column($rep["report"]["activities"], null, "id")[$a[1]]["history"]) === 1, json_encode($rep["report"]));

// ── 5. A send-back on an earlier day (F1, F11) ───────────────────────────

[$status, $r] = drPost($owner, ["action" => "plan", "admin_ids" => [43], "reviewer_id" => 1, "activities" => [["title" => "Screen CVs", "count_unit" => "CVs"]]]);
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

// ── 7. "Open Daily Reports" off (F2) ─────────────────────────────────────

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

// ── 9. Departments ───────────────────────────────────────────────────────

[$status, $r] = post("api/departments.php", $mark, ["action" => "create", "name" => "Sales"]);
check("departments: only the Director changes them", $status === 403, "HTTP $status");
[$status, $r] = post("api/departments.php", $owner, ["action" => "create", "name" => "Sales"]);
check("departments: the Director adds one", $status === 200 && ($r["departments"][0]["name"] ?? "") === "Sales", json_encode($r));
[$status, $r] = post("api/departments.php", $owner, ["action" => "create", "name" => "sales"]);
check("departments: names are unique", $status === 409, "HTTP $status");
[$status, $r] = pageGet("api/departments.php", $hana);
check("departments: anyone can list them", $status === 200 && count($r["departments"] ?? []) === 1, json_encode($r));
