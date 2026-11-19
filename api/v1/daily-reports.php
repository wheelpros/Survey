<?php

/*
| Daily reports (api/daily-report-lib.php has the rules).
|
|   GET daily-reports.php              list_my_daily_reports    my reports, newest first
|   GET daily-reports.php?id=N         get_daily_report         one report I may see
|   GET daily-reports.php?team=1       list_team_daily_reports  the reports I review,
|                                      plan or see: from, to, status, department, paged
|
| Scope daily_reports:read, and "Open Daily Reports" (SCOPE_PAGES). The same
| rules as the pages: the author, the planner, the reviewer, reports.review
| for their own team, and reports.view_all see a report; anyone else a 404.
|
| What people typed - titles, descriptions, outcomes, feedback, notes, count
| units, file names - is under untrusted_content.
|
| Filling in, closing and reviewing go through prepare/confirm
| (update_daily_activity, close_daily_report, review_daily_activity,
| review_daily_report in _writes.php). Planning stays in the portal.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../daily-report-lib.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "daily_reports:read");

ensureDailyReportTables($pdo);

/** A report as the lists show it. */
function v1DailyItem(PDO $pdo, array $p, array $r)
{
    $s = drSummary($pdo, $p, $r);
    return [
        "id" => $r["id"],
        "report_date" => $r["report_date"],
        "person" => ["id" => $r["admin_id"], "name" => $r["admin_name"]],
        "department" => v1NullableString($r["department_name"]),
        "status" => $s["effective_status"],
        "closed" => $r["status"] !== "planned",
        "activities_done" => $s["activities_done"],
        "activities_total" => $s["activities_total"],
        "to_review" => $s["to_review"],
        "planned_by" => ["id" => $r["created_by"], "name" => $r["created_by_name"]],
        "reviewer" => $r["reviewer_id"] ? ["id" => $r["reviewer_id"], "name" => (string) $r["reviewer_name"]] : null,
    ];
}

$id = v1IntParam("id");

if ($id !== null) {
    $r = drFind($pdo, $id);
    if (!$r || !drCanSee($pdo, $p, $r)) {
        v1Error(404, "not_found", "No daily report with that id that you may see.");
    }
    $flags = drFlags($pdo, $p, $r);
    $out = v1DailyItem($pdo, $p, $r) + [
        "can_edit" => $flags["can_fill"],
        "can_fix_sent_back" => $flags["can_fix"],
        "can_review_activities" => $flags["can_review_activities"],
        "can_review_report" => $flags["can_review"],
        "review_blocked" => $flags["review_blocked"],
        "reviewed" => $r["status"] === "reviewed" ? [
            "by" => (string) $r["reviewed_by_name"],
            "at" => v1Time($r["reviewed_ts"]),
        ] : null,
        "activities" => array_map(function ($a, $i) {
            return [
                "id" => $a["id"],
                "number" => $i + 1,
                "status" => $a["status"],
                "count_value" => $a["count_value"],
                "review" => $a["review"] ? [
                    "state" => $a["review"]["state"],
                    "by" => $a["review"]["by_name"],
                    "at" => v1Time($a["review"]["at"]),
                ] : null,
                "awaiting_review" => drAwaiting($a),
                "sent_back" => drSentBack($a),
                "untrusted_content" => [
                    "title" => $a["title"],
                    "description" => $a["description"],
                    "outcome" => $a["outcome"],
                    "count_unit" => $a["count_unit"],
                    "feedback" => $a["review"] ? $a["review"]["feedback"] : "",
                ],
            ];
        }, $r["activities"], array_keys($r["activities"])),
        "files" => array_map(function ($f) {
            return ["id" => $f["id"], "size" => $f["size"], "untrusted_content" => ["name" => $f["name"]]];
        }, $r["files"]),
        "untrusted_content" => ["reviewer_note" => $r["reviewer_note"]],
    ];
    v1Reply(200, $out);
}

if (v1IntParam("team") === 1) {
    if (!(can($p, "reports.review", $pdo) || drSeesAll($pdo, $p) || can($p, "reports.create", $pdo))) {
        v1Error(403, "forbidden", "Only reviewers, planners and whoever sees every report have Team reports.");
    }
    $today = drToday($pdo);
    $from = v1DateParam("from", $today);
    $to = v1DateParam("to", $from);
    $status = v1EnumParam("status", array_keys(DR_STATUS_RANK));
    [$scope, $params] = drTeamScopeSql($pdo, $p);
    $where = $scope . " AND r.report_date BETWEEN ? AND ?";
    $params = array_merge($params, [$from, $to]);
    $dept = v1IntParam("department");
    if ($dept) {
        $where .= " AND r.department_id = ?";
        $params[] = $dept;
    }

    $rows = [];
    foreach (drFetch($pdo, $where, $params) as $r) {
        $item = v1DailyItem($pdo, $p, $r);
        if ($status === null || $item["status"] === $status) {
            $rows[] = $item;
        }
    }
    usort($rows, function ($a, $b) {
        return strcmp($b["report_date"], $a["report_date"])
            ?: (DR_STATUS_RANK[$a["status"]] <=> DR_STATUS_RANK[$b["status"]])
            ?: strcmp($a["person"]["name"], $b["person"]["name"]);
    });

    $limit = v1Limit();
    $start = max(0, (int) ($_GET["cursor"] ?? 0));
    v1Reply(200, [
        "from" => $from,
        "to" => $to,
        "items" => array_slice($rows, $start, $limit),
        "next_cursor" => $start + $limit < count($rows) ? (string) ($start + $limit) : null,
        "waiting_for_me" => drWaitingForMe($pdo, $p),
    ]);
}

$today = drToday($pdo);
$mine = drFetch($pdo, "r.admin_id = ?", [(int) $p["id"]], false, "ORDER BY r.report_date DESC, r.id DESC LIMIT 30");
$items = array_values(array_map(function ($r) use ($pdo, $p) { return v1DailyItem($pdo, $p, $r); }, $mine));
$todays = array_values(array_filter($items, function ($i) use ($today) { return $i["report_date"] === $today; }));

v1Reply(200, [
    "today" => $today,
    "today_report" => $todays[0] ?? null,
    "needs_me" => drPendingCount($pdo, $p),
    "can_plan_own" => can($p, "reports.self", $pdo),
    "items" => $items,
]);
