<?php

/*
|--------------------------------------------------------------------------
| Activity reports: the rules, in one place
|--------------------------------------------------------------------------
|
| A planner writes activities for someone: reports.create for their team
| (everyone, for the Director and reports.view_all), reports.self for their
| own report. Anyone may hold any number of reports, several on one day. The
| person fills each in on the report page - each activity's status, outcome
| and count, plus files - and sends it whenever it's ready, with an end-of-day
| note (outstanding work, tomorrow's priorities, challenges). Its reviewers
| (daily_report_reviewers) review each Done activity as soon as it's saved -
| Reviewed, or Needs changes with feedback - and the whole report once it's
| sent. Any one of them completes the review.
|
| Who the reviewers are: whoever planned it, for a report planned for someone;
| the people the author picks, for their own (the Director, or anyone with
| reports.review).
|
| The rules, as the audit left them (docs: the fix plan of 2026-11-19):
|
|   - A report's date is the portal's day it was made (portalTimezone(), never
|     the browser's). It is a label: nothing locks when the day ends.
|   - The author edits their report until they send it. After that only
|     activities sent back for changes.
|   - Edit plan: only until it's sent, and only safe changes. Activities are
|     matched by id; one that was worked on or reviewed can't be removed or
|     retitled. Your own report's plan also carries each new or untouched
|     activity's status, outcome and count.
|   - Delete: the planner, only before anything happened (not started).
|   - Reviewing: the report's reviewers, the Director, or reports.review for
|     their own team. reports.view_all sees every report but reviews none by
|     itself. Nobody reviews their own report.
|
| Used by api/activity-reports.php (reads), api/activity-report-writes.php (writes),
| api/admin-dashboard.php (the cards) and api/v1 (the MCP tools), so every way
| in follows the same rules. Refusals throw PortalWriteError (db.php).
|
| Callers run ensureDailyReportTables() first: it is DDL, which can't happen
| inside the transactions below.
|
| Notifications go out after each commit:
|   planned, or the plan changed      -> the employee
|   your own report created or saved  -> every reviewer, one row per report
|   sent, or sent-back work fixed     -> every reviewer
|   activities reviewed               -> the employee, one merged row per report
|   report reviewed, or deleted       -> the employee
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/team-scope.php";
require_once __DIR__ . "/upload-helpers.php";
require_once __DIR__ . "/availability.php"; // portalTimezone()

const DR_ACTIVITY_STATUSES = ["todo", "in_progress", "follow_up", "done"];
const DR_CHALLENGE_PRIORITIES = ["low", "medium", "high"];
const DR_MAX_ACTIVITIES = 30;
const DR_MAX_TITLE = 150;
const DR_LIST_PAGE_MAX = 100;

/* Effective statuses, as the lists sort them (most in need of someone first). */
const DR_STATUS_RANK = ["awaiting" => 0, "changes" => 1, "sent" => 2, "in_progress" => 3, "planned" => 4, "reviewed" => 5];

/** The portal's date today, YYYY-MM-DD. */
function drToday(PDO $pdo)
{
    static $today = null;
    if ($today === null) {
        $today = (new DateTime("now", new DateTimeZone(portalTimezone($pdo))))->format("Y-m-d");
    }
    return $today;
}

/*
|--------------------------------------------------------------------------
| Who may do what
|--------------------------------------------------------------------------
*/

function drSeesAll(PDO $pdo, array $p)
{
    return can($p, "reports.view_all", $pdo); // the Director passes every can()
}

/** The people $p manages (teamPoolFor), cached per request. */
function drPool(PDO $pdo, array $p)
{
    static $cache = [];
    $key = (int) $p["id"];
    if (!isset($cache[$key])) {
        $cache[$key] = array_map("intval", array_keys(teamPoolFor($pdo, $p)));
    }
    return $cache[$key];
}

function drReviewsTeam(PDO $pdo, array $p, $adminId)
{
    return can($p, "reports.review", $pdo) && in_array((int) $adminId, drPool($pdo, $p), true);
}

function drCanSee(PDO $pdo, array $p, array $r)
{
    $me = (int) $p["id"];
    return drSeesAll($pdo, $p) || $r["admin_id"] === $me || in_array($me, $r["reviewer_ids"], true) || $r["created_by"] === $me
        || drReviewsTeam($pdo, $p, $r["admin_id"]);
}

function drAwaiting(array $a)
{
    return $a["status"] === "done" && !($a["review"] && $a["review"]["state"] === "approved");
}

function drSentBack(array $a)
{
    return $a["review"] && $a["review"]["state"] === "changes" && $a["status"] !== "done";
}

/** Worked on, or reviewed: the plan can't remove or retitle it any more. */
function drTouched(array $a)
{
    return $a["status"] !== "todo" || trim((string) $a["outcome"]) !== "" || $a["count_value"] !== null
        || $a["review"] !== null || !empty($a["history_count"]);
}

/** What the report is waiting on, first match wins. */
function drEffectiveStatus(array $r)
{
    foreach ($r["activities"] as $a) {
        if (drAwaiting($a)) {
            return "awaiting";
        }
    }
    foreach ($r["activities"] as $a) {
        if (drSentBack($a)) {
            return "changes";
        }
    }
    if ($r["status"] === "reviewed") {
        return "reviewed";
    }
    if ($r["status"] === "submitted") {
        return "sent";
    }
    return $r["started"] ? "in_progress" : "planned";
}

function drAnySentBack(array $r)
{
    foreach ($r["activities"] as $a) {
        if (drSentBack($a)) {
            return true;
        }
    }
    return false;
}

/** The author, until they send it. */
function drCanFill(array $p, array $r)
{
    return $r["admin_id"] === (int) $p["id"] && $r["status"] === "planned";
}

/** The author fixing what was sent back, once the report is sent. */
function drCanFix(array $p, array $r)
{
    return $r["admin_id"] === (int) $p["id"] && $r["status"] !== "reviewed" && !drCanFill($p, $r) && drAnySentBack($r);
}

function drCanPlan(array $p, array $r)
{
    return $r["created_by"] === (int) $p["id"] && $r["status"] === "planned";
}

/**
 * reports.self on a report someone else planned: while it's open, the author
 * adds activities of their own (origin "employee"). Their own self-planned
 * report doesn't need this - they change it with Edit plan.
 */
function drCanAddOwn(PDO $pdo, array $p, array $r)
{
    return drCanFill($p, $r) && !drCanPlan($p, $r) && can($p, "reports.self", $pdo);
}

function drCanDelete(array $p, array $r)
{
    return $r["created_by"] === (int) $p["id"] && drEffectiveStatus($r) === "planned";
}

function drCanReviewActivities(PDO $pdo, array $p, array $r)
{
    $me = (int) $p["id"];
    if ($r["status"] === "reviewed" || $r["admin_id"] === $me) {
        return false;
    }
    return in_array($me, $r["reviewer_ids"], true) || isDirector($p) || drReviewsTeam($pdo, $p, $r["admin_id"]);
}

function drCanReviewReport(PDO $pdo, array $p, array $r)
{
    return drCanReviewActivities($pdo, $p, $r) && $r["status"] === "submitted" && !drAnySentBack($r);
}

/** People $p may plan reports for: active, not the Director, not themselves, on their team (everyone when they see all). */
function drPlannable(PDO $pdo, array $p)
{
    if (!can($p, "reports.create", $pdo)) {
        return [];
    }
    $rows = $pdo->query("
        SELECT a.id, a.name, a.role, a.position, a.department_id, d.name AS department_name
        FROM admins a LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.active = 1 AND a.role IN ('account_manager', 'super_admin', 'seo_admin')
        ORDER BY a.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pool = drSeesAll($pdo, $p) ? null : drPool($pdo, $p);
    $out = [];
    foreach ($rows as $x) {
        $id = (int) $x["id"];
        if ($id === (int) $p["id"] || ($pool !== null && !in_array($id, $pool, true))) {
            continue;
        }
        // Someone whose Activity Reports page is turned off couldn't open,
        // fill in or send a report planned for them, so they aren't offered.
        if (!can(["id" => $id, "role" => $x["role"]], "pages.daily_reports", $pdo)) {
            continue;
        }
        $out[] = ["id" => $id, "name" => $x["name"], "role" => $x["role"], "position" => $x["position"],
            "department_id" => $x["department_id"] !== null ? (int) $x["department_id"] : null,
            "department_name" => $x["department_name"]];
    }
    return $out;
}

/**
 * Who can be picked to review your own report: the Director, and every active
 * admin who may review team reports (reports.review) and open Activity Reports.
 */
function drReviewers(PDO $pdo)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = $pdo->query("
        SELECT a.id, a.name, a.role, a.position, a.department_id, d.name AS department_name
        FROM admins a LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.active = 1
        ORDER BY a.role = 'owner' DESC, a.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $cache = [];
    foreach ($rows as $x) {
        $who = ["id" => (int) $x["id"], "role" => $x["role"]];
        if (!isDirector($who) && !(can($who, "reports.review", $pdo) && can($who, "pages.daily_reports", $pdo))) {
            continue;
        }
        $cache[] = ["id" => (int) $x["id"], "name" => $x["name"], "role" => $x["role"], "position" => $x["position"],
            "department_id" => $x["department_id"] !== null ? (int) $x["department_id"] : null,
            "department_name" => $x["department_name"]];
    }
    return $cache;
}

/** Your own report's reviewers, checked: one or more, each eligible, never you. Returns the ids. */
function drCheckReviewers(PDO $pdo, $ids, $authorId)
{
    $ids = array_values(array_unique(array_filter(array_map("intval", is_array($ids) ? $ids : []))));
    if (!$ids) {
        throw new PortalWriteError("Pick who reviews it.");
    }
    if (in_array((int) $authorId, $ids, true)) {
        throw new PortalWriteError("You can't review your own report. Pick someone else.");
    }
    $eligible = array_column(drReviewers($pdo), "id");
    foreach ($ids as $id) {
        if (!in_array($id, $eligible, true)) {
            throw new PortalWriteError(eventAdminName($pdo, $id) . " can't review activity reports. Pick the Director or someone who reviews team reports.");
        }
    }
    return $ids;
}

/** Replaces a report's reviewers (inside the caller's transaction). */
function drSetReviewers(PDO $pdo, $reportId, array $ids)
{
    $pdo->prepare("DELETE FROM daily_report_reviewers WHERE report_id = ?")->execute([(int) $reportId]);
    $insert = $pdo->prepare("INSERT INTO daily_report_reviewers (report_id, admin_id) VALUES (?, ?)");
    foreach ($ids as $id) {
        $insert->execute([(int) $reportId, (int) $id]);
    }
    // The old single column, kept filled for anything still reading it.
    $pdo->prepare("UPDATE daily_reports SET reviewer_admin_id = ? WHERE id = ?")->execute([$ids ? (int) $ids[0] : null, (int) $reportId]);
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

/**
 * Reports matching $where (SQL on `r`), with their activities. $full adds
 * each activity's review history and the report's files.
 */
function drFetch(PDO $pdo, $where, array $params, $full = false, $orderLimit = "ORDER BY r.report_date DESC, r.id DESC")
{
    $stmt = $pdo->prepare("
        SELECT r.*, UNIX_TIMESTAMP(r.created_at) AS created_ts, UNIX_TIMESTAMP(r.updated_at) AS updated_ts,
               UNIX_TIMESTAMP(r.submitted_at) AS submitted_ts, UNIX_TIMESTAMP(r.reviewed_at) AS reviewed_ts,
               a.name AS admin_name, a.role AS admin_role, a.position AS admin_position,
               cb.name AS created_by_name, rb.name AS reviewed_by_name,
               d.name AS department_name, t.name AS template_name
        FROM daily_reports r
        JOIN admins a ON a.id = r.admin_id
        LEFT JOIN admins cb ON cb.id = r.created_by
        LEFT JOIN admins rb ON rb.id = r.reviewed_by
        LEFT JOIN departments d ON d.id = r.department_id
        LEFT JOIN daily_report_templates t ON t.id = r.template_id
        " . ($where !== "" ? "WHERE " . $where : "") . "
        $orderLimit
    ");
    $stmt->execute($params);

    $reports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reports[(int) $row["id"]] = [
            "id" => (int) $row["id"],
            "admin_id" => (int) $row["admin_id"],
            "admin_name" => (string) $row["admin_name"],
            "admin_role" => (string) $row["admin_role"],
            "admin_position" => $row["admin_position"],
            "created_by" => (int) $row["created_by"],
            "created_by_name" => (string) $row["created_by_name"],
            // Filled in below from daily_report_reviewers. reviewer_id and
            // reviewer_name stay for older readers: the first one, and every
            // name joined ("Sarah Miller, James Carter").
            "reviewers" => [],
            "reviewer_ids" => [],
            "reviewer_id" => null,
            "reviewer_name" => null,
            "outstanding" => (string) ($row["outstanding"] ?? ""),
            "priorities" => (string) ($row["priorities"] ?? ""),
            "challenges" => (string) ($row["challenges"] ?? ""),
            "challenge_priority" => $row["challenge_priority"] ?? null,
            "department_id" => $row["department_id"] !== null ? (int) $row["department_id"] : null,
            "department_name" => $row["department_name"],
            "template_id" => $row["template_id"] !== null ? (int) $row["template_id"] : null,
            "template_name" => $row["template_name"],
            "title" => (string) ($row["title"] ?? ""),
            "report_date" => (string) $row["report_date"],
            "status" => (string) $row["status"],
            "started" => (bool) $row["started"],
            "reviewer_note" => (string) ($row["reviewer_note"] ?? ""),
            "reviewed_by" => $row["reviewed_by"] !== null ? (int) $row["reviewed_by"] : null,
            "reviewed_by_name" => $row["reviewed_by_name"],
            "created_ts" => (int) $row["created_ts"],
            "updated_ts" => $row["updated_ts"] !== null ? (int) $row["updated_ts"] : (int) $row["created_ts"],
            "submitted_ts" => $row["submitted_ts"] !== null ? (int) $row["submitted_ts"] : null,
            "reviewed_ts" => $row["reviewed_ts"] !== null ? (int) $row["reviewed_ts"] : null,
            "activities" => [],
            "files" => [],
            "file_count" => 0,
        ];
    }
    if (!$reports) {
        return [];
    }

    $ids = array_keys($reports);
    $marks = implode(",", array_fill(0, count($ids), "?"));

    $stmt = $pdo->prepare("
        SELECT v.report_id, v.admin_id, a.name
        FROM daily_report_reviewers v JOIN admins a ON a.id = v.admin_id
        WHERE v.report_id IN ($marks)
        ORDER BY v.report_id, a.role = 'owner' DESC, a.name
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $rid = (int) $v["report_id"];
        $reports[$rid]["reviewers"][] = ["id" => (int) $v["admin_id"], "name" => (string) $v["name"]];
        $reports[$rid]["reviewer_ids"][] = (int) $v["admin_id"];
    }
    foreach ($reports as $rid => $r) {
        if ($r["reviewers"]) {
            $reports[$rid]["reviewer_id"] = $r["reviewers"][0]["id"];
            $reports[$rid]["reviewer_name"] = implode(", ", array_column($r["reviewers"], "name"));
        }
    }

    $stmt = $pdo->prepare("
        SELECT x.*, UNIX_TIMESTAMP(x.done_at) AS done_ts, UNIX_TIMESTAMP(x.reviewed_at) AS reviewed_ts,
               rb.name AS reviewed_by_name,
               (SELECT COUNT(*) FROM daily_report_activity_reviews h WHERE h.activity_id = x.id) AS history_count
        FROM daily_report_activities x
        LEFT JOIN admins rb ON rb.id = x.reviewed_by
        WHERE x.report_id IN ($marks)
        ORDER BY x.report_id, x.sort_order, x.id
    ");
    $stmt->execute($ids);
    $byActivity = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $a = [
            "id" => (int) $x["id"],
            "title" => (string) $x["title"],
            "description" => (string) ($x["description"] ?? ""),
            "origin" => (string) $x["origin"],
            "count_unit" => $x["count_unit"],
            "count_value" => $x["count_value"] !== null ? (int) $x["count_value"] : null,
            "status" => (string) $x["status"],
            "outcome" => (string) ($x["outcome"] ?? ""),
            "done_at" => $x["done_ts"] !== null ? (int) $x["done_ts"] : null,
            "review" => $x["review_state"] ? [
                "state" => (string) $x["review_state"],
                "feedback" => (string) ($x["review_feedback"] ?? ""),
                "by" => (int) $x["reviewed_by"],
                "by_name" => (string) $x["reviewed_by_name"],
                "at" => (int) $x["reviewed_ts"],
            ] : null,
            "history_count" => (int) $x["history_count"],
            "history" => [],
        ];
        $reports[(int) $x["report_id"]]["activities"][] = $a;
        $byActivity[$a["id"]] = [(int) $x["report_id"], count($reports[(int) $x["report_id"]]["activities"]) - 1];
    }

    if ($full && $byActivity) {
        $aIds = array_keys($byActivity);
        $stmt = $pdo->prepare("
            SELECT h.activity_id, h.state, h.feedback, h.reviewed_by, UNIX_TIMESTAMP(h.reviewed_at) AS at, a.name AS by_name
            FROM daily_report_activity_reviews h LEFT JOIN admins a ON a.id = h.reviewed_by
            WHERE h.activity_id IN (" . implode(",", array_fill(0, count($aIds), "?")) . ")
            ORDER BY h.id
        ");
        $stmt->execute($aIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            [$rid, $i] = $byActivity[(int) $h["activity_id"]];
            $reports[$rid]["activities"][$i]["history"][] = [
                "state" => (string) $h["state"], "feedback" => (string) ($h["feedback"] ?? ""),
                "by" => (int) $h["reviewed_by"], "by_name" => (string) $h["by_name"], "at" => (int) $h["at"],
            ];
        }
    }

    $files = attachmentsFor($pdo, DAILY_REPORT_FILES_OWNER, $ids);
    foreach ($files as $rid => $list) {
        $reports[$rid]["file_count"] = count($list);
        if ($full) {
            $reports[$rid]["files"] = array_map(function ($f) {
                return ["id" => $f["id"], "name" => $f["name"], "size" => $f["size"]];
            }, $list);
        }
    }

    return $reports;
}

/** One report by id, or null. */
function drFind(PDO $pdo, $id, $full = true)
{
    $rows = drFetch($pdo, "r.id = ?", [(int) $id], $full);
    return $rows[(int) $id] ?? null;
}

/** "31 calls · 42 CVs": counted totals by unit. */
function drCounts(array $reports)
{
    $totals = [];
    foreach ($reports as $r) {
        foreach ($r["activities"] as $a) {
            if ($a["count_unit"] && $a["count_value"] !== null) {
                $totals[$a["count_unit"]] = ($totals[$a["count_unit"]] ?? 0) + $a["count_value"];
            }
        }
    }
    $out = [];
    foreach ($totals as $unit => $total) {
        $out[] = ["unit" => $unit, "total" => $total];
    }
    return $out;
}

/** Activities by status over $reports: the Activity Reports donut. */
function drActivityCounts(array $reports)
{
    $counts = array_fill_keys(DR_ACTIVITY_STATUSES, 0);
    foreach ($reports as $r) {
        foreach ($r["activities"] as $a) {
            if (isset($counts[$a["status"]])) {
                $counts[$a["status"]]++;
            }
        }
    }
    return $counts;
}

/** A report as the lists show it. */
function drSummary(PDO $pdo, array $p, array $r)
{
    $done = 0;
    $toReview = 0;
    foreach ($r["activities"] as $a) {
        $done += $a["status"] === "done" ? 1 : 0;
        $toReview += drAwaiting($a) ? 1 : 0;
    }
    return [
        "id" => $r["id"], "admin_id" => $r["admin_id"], "admin_name" => $r["admin_name"], "admin_role" => $r["admin_role"],
        "admin_position" => $r["admin_position"],
        "department_id" => $r["department_id"], "department_name" => $r["department_name"],
        "created_by" => $r["created_by"], "created_by_name" => $r["created_by_name"], "self_made" => $r["created_by"] === $r["admin_id"],
        "template_name" => $r["template_name"], "title" => $r["title"],
        "report_date" => $r["report_date"], "status" => $r["status"], "effective_status" => drEffectiveStatus($r),
        "to_review" => $toReview, "activities_total" => count($r["activities"]), "activities_done" => $done,
        "counts" => drCounts([$r]), "activity_counts" => drActivityCounts([$r]),
        "reviewer_id" => $r["reviewer_id"], "reviewer_name" => $r["reviewer_name"],
        "reviewers" => $r["reviewers"], "reviewer_ids" => $r["reviewer_ids"],
        "challenge_priority" => $r["challenge_priority"],
        "reviewed_by" => $r["reviewed_by"], "reviewed_by_name" => $r["reviewed_by_name"], "reviewer_note" => $r["reviewer_note"],
        "reviewed_ts" => $r["reviewed_ts"], "submitted_ts" => $r["submitted_ts"], "updated_ts" => $r["updated_ts"], "created_ts" => $r["created_ts"],
        "file_count" => $r["file_count"],
        "can_plan" => drCanPlan($p, $r), "can_delete" => drCanDelete($p, $r),
    ];
}

/** A report as its own page shows it: the summary, its activities and files. */
function drFull(PDO $pdo, array $p, array $r)
{
    $acts = array_map(function ($a) {
        $a["plan_locked"] = drTouched($a);
        unset($a["history_count"]);
        return $a;
    }, $r["activities"]);
    return array_merge(drSummary($pdo, $p, $r), ["activities" => $acts, "files" => $r["files"],
        "outstanding" => $r["outstanding"], "priorities" => $r["priorities"], "challenges" => $r["challenges"]]);
}

/** The flags the report page needs, beside the report itself. */
function drFlags(PDO $pdo, array $p, array $r)
{
    $canReviewActivities = drCanReviewActivities($pdo, $p, $r);
    return [
        "can_fill" => drCanFill($p, $r),
        "can_fix" => drCanFix($p, $r),
        "can_plan" => drCanPlan($p, $r),
        "can_delete" => drCanDelete($p, $r),
        "can_add_own" => drCanAddOwn($pdo, $p, $r),
        "can_review_activities" => $canReviewActivities,
        "can_review" => drCanReviewReport($pdo, $p, $r),
        "review_blocked" => $canReviewActivities && $r["status"] === "submitted" && drAnySentBack($r),
    ];
}

/**
 * SQL (on `r`) for the other people's reports $p may see - the Team reports
 * list. Returns [$sql, $params].
 */
function drTeamScopeSql(PDO $pdo, array $p)
{
    $me = (int) $p["id"];
    if (drSeesAll($pdo, $p)) {
        return ["r.admin_id <> ?", [$me]];
    }
    $parts = ["EXISTS (SELECT 1 FROM daily_report_reviewers v WHERE v.report_id = r.id AND v.admin_id = ?)", "r.created_by = ?"];
    if (can($p, "reports.review", $pdo)) {
        $pool = drPool($pdo, $p);
        if ($pool) {
            $parts[] = "r.admin_id IN (" . implode(",", array_map("intval", $pool)) . ")";
        }
    }
    return ["r.admin_id <> ? AND (" . implode(" OR ", $parts) . ")", [$me, $me, $me]];
}

/** How many of $p's reports need them: every one not sent yet, plus any with work sent back to fix. */
function drPendingCount(PDO $pdo, array $p)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM daily_reports r
        WHERE r.admin_id = ?
          AND (r.status = 'planned'
            OR (r.status <> 'reviewed' AND EXISTS (
                  SELECT 1 FROM daily_report_activities x
                  WHERE x.report_id = r.id AND x.review_state = 'changes' AND x.status <> 'done')))
    ");
    $stmt->execute([(int) $p["id"]]);
    return (int) $stmt->fetchColumn();
}

/** Reports addressed to $p with Done activities not reviewed yet. */
function drWaitingForMe(PDO $pdo, array $p)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM daily_reports r
        WHERE r.status <> 'reviewed'
          AND EXISTS (SELECT 1 FROM daily_report_reviewers v WHERE v.report_id = r.id AND v.admin_id = ?)
          AND EXISTS (
            SELECT 1 FROM daily_report_activities x
            WHERE x.report_id = r.id AND x.status = 'done' AND (x.review_state IS NULL OR x.review_state <> 'approved'))
    ");
    $stmt->execute([(int) $p["id"]]);
    return (int) $stmt->fetchColumn();
}

/**
 * The dashboard's numbers for $p, in one call: their first report today and
 * how many of theirs are still open, how many reports wait for their review,
 * and - for whoever sees the team's - today's reports plus every older one not
 * sent yet, counted by effective status.
 */
function drSummaryFor(PDO $pdo, array $p, $departmentId = null)
{
    $today = drToday($pdo);
    $todays = drFetch($pdo, "r.admin_id = ? AND r.report_date = ?", [(int) $p["id"], $today], false, "ORDER BY r.id");
    $mine = $todays ? reset($todays) : null;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_reports WHERE admin_id = ? AND status = 'planned'");
    $stmt->execute([(int) $p["id"]]);

    $out = [
        "today" => $today,
        "my_report" => $mine ? drSummary($pdo, $p, $mine) : null,
        "my_open" => (int) $stmt->fetchColumn(),
        "pending" => drPendingCount($pdo, $p),
        "waiting_for_me" => drWaitingForMe($pdo, $p),
        "team" => null,
    ];

    if (drSeesAll($pdo, $p) || can($p, "reports.review", $pdo) || can($p, "reports.create", $pdo)) {
        [$scope, $params] = drTeamScopeSql($pdo, $p);
        $where = $scope . " AND (r.report_date = ? OR r.status = 'planned')";
        $params[] = $today;
        if ($departmentId === "none") {
            $where .= " AND r.department_id IS NULL";
        } elseif ($departmentId) {
            $where .= " AND r.department_id = ?";
            $params[] = (int) $departmentId;
        }
        $counts = array_fill_keys(array_keys(DR_STATUS_RANK), 0);
        foreach (drFetch($pdo, $where, $params) as $r) {
            $counts[drEffectiveStatus($r)]++;
        }
        $out["team"] = $counts;
        $out["departments"] = array_map(function ($d) {
            return ["id" => (int) $d["id"], "name" => (string) $d["name"]];
        }, $pdo->query("SELECT id, name FROM departments WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC));
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| Templates
|--------------------------------------------------------------------------
*/

function drCanEditTemplate(array $p, array $t)
{
    return isDirector($p) || (int) $t["created_by"] === (int) $p["id"];
}

function drTemplates(PDO $pdo, array $p, $id = null)
{
    $sql = "
        SELECT t.*, d.name AS department_name, cb.name AS created_by_name
        FROM daily_report_templates t
        LEFT JOIN departments d ON d.id = t.department_id
        LEFT JOIN admins cb ON cb.id = t.created_by
        WHERE t.active = 1" . ($id ? " AND t.id = ?" : "") . "
        ORDER BY t.name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($id ? [(int) $id] : []);
    return array_map(function ($t) use ($p) {
        $acts = json_decode((string) $t["activities"], true);
        return [
            "id" => (int) $t["id"], "name" => (string) $t["name"],
            "department_id" => $t["department_id"] !== null ? (int) $t["department_id"] : null,
            "department_name" => $t["department_name"],
            "activities" => is_array($acts) ? $acts : [],
            "created_by" => (int) $t["created_by"], "created_by_name" => (string) $t["created_by_name"],
            "active" => (int) $t["active"],
            "can_edit" => drCanEditTemplate($p, $t),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/*
|--------------------------------------------------------------------------
| Notifications (always after commit - notify() refuses inside one)
|--------------------------------------------------------------------------
*/

function drLink($reportId)
{
    return "activity-report-details.html?id=" . (int) $reportId;
}

/** "3 of 5 done": the line the notifications use. */
function drProgressText(array $r)
{
    $done = count(array_filter($r["activities"], function ($a) { return $a["status"] === "done"; }));
    return $done . " of " . count($r["activities"]) . " done";
}

/** The employee's merged "your activities were reviewed" row, from where the report stands now. */
function drNotifyActivityReviews(PDO $pdo, array $p, $reportId)
{
    $r = drFind($pdo, $reportId, false);
    if (!$r) {
        return;
    }
    $ok = 0;
    $changes = 0;
    foreach ($r["activities"] as $a) {
        $ok += $a["review"] && $a["review"]["state"] === "approved" ? 1 : 0;
        $changes += drSentBack($a) ? 1 : 0;
    }
    $parts = [];
    if ($ok) {
        $parts[] = $ok . " reviewed";
    }
    if ($changes) {
        $parts[] = $changes . ($changes === 1 ? " needs" : " need") . " changes";
    }
    notifyReplacing($pdo, "admin", $r["admin_id"], NOTIFY_DAILY_ACTIVITY_REVIEWED,
        $changes ? $p["name"] . " asked for changes on your activity report" : $p["name"] . " reviewed your activities",
        "Your report for " . $r["report_date"] . ": " . implode(", ", $parts) . ".",
        drLink($r["id"]), "admin", (int) $p["id"]);
}

/**
 * Your own report, created or its plan saved: each reviewer's one row for it,
 * replaced on every save. $dropped are reviewers taken off it: their unread
 * row goes, since the report isn't theirs to look at any more.
 */
function drNotifyReviewersOfOwn(PDO $pdo, array $p, $reportId, $created, array $dropped = [])
{
    $r = drFind($pdo, $reportId, false);
    if (!$r) {
        return;
    }
    if ($dropped) {
        try {
            $pdo->prepare("DELETE FROM notifications WHERE recipient_kind = 'admin' AND event_type = ? AND link = ? AND read_at IS NULL
                           AND recipient_id IN (" . implode(",", array_map("intval", $dropped)) . ")")
                ->execute([NOTIFY_DAILY_REPORT, drLink($r["id"])]);
        } catch (Throwable $e) {
            // A leftover row opens a report they can't see; harmless.
        }
    }
    $waiting = count(array_filter($r["activities"], "drAwaiting"));
    foreach ($r["reviewer_ids"] as $reviewerId) {
        notifyReplacing($pdo, "admin", $reviewerId, NOTIFY_DAILY_REPORT,
            $r["admin_name"] . ($created ? " created an activity report" : " updated their activity report"),
            drProgressText($r) . ($waiting ? ", " . $waiting . " waiting for your review." : "."),
            drLink($r["id"]), "admin", (int) $p["id"]);
    }
}

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

/**
 * A planner's activities, checked and tidied: [{id, title, description,
 * count_unit, origin, progress}]. $withProgress (your own report) also reads
 * each one's status, outcome and count_value into progress; otherwise, and
 * when none of the three is given, progress is null.
 */
function drCleanPlan($list, $withProgress = false)
{
    $out = [];
    foreach (is_array($list) ? $list : [] as $x) {
        if (!is_array($x)) {
            continue;
        }
        $title = mb_substr(trim((string) ($x["title"] ?? "")), 0, 200);
        if ($title === "") {
            continue;
        }
        $unit = mb_substr(trim((string) ($x["count_unit"] ?? "")), 0, 30);
        $out[] = [
            "id" => (int) ($x["id"] ?? 0),
            "title" => $title,
            "description" => mb_substr(trim((string) ($x["description"] ?? "")), 0, 2000),
            "count_unit" => $unit !== "" ? $unit : null,
            "origin" => ($x["origin"] ?? "") === "carried" ? "carried" : "planned",
            "progress" => $withProgress ? drCleanProgress($x, $title, $unit !== "") : null,
        ];
    }
    if (!$out) {
        throw new PortalWriteError("Add at least one activity.");
    }
    if (count($out) > DR_MAX_ACTIVITIES) {
        throw new PortalWriteError("A report can have up to " . DR_MAX_ACTIVITIES . " activities.");
    }
    return $out;
}

/** One activity's status, outcome and count from a plan, or null when none is given. */
function drCleanProgress(array $x, $title, $counted)
{
    if (!array_key_exists("status", $x) && !array_key_exists("outcome", $x) && !array_key_exists("count_value", $x)) {
        return null;
    }
    $status = (string) ($x["status"] ?? "todo");
    if (!in_array($status, DR_ACTIVITY_STATUSES, true)) {
        throw new PortalWriteError("Pick a status for “" . $title . "”.");
    }
    $count = $x["count_value"] ?? null;
    if ($count === "" || $count === null) {
        $count = null;
    } elseif (!is_numeric($count) || (int) $count != $count || (int) $count < 0) {
        throw new PortalWriteError("Counts are whole numbers, 0 or more.");
    } else {
        $count = (int) $count;
    }
    return ["status" => $status, "outcome" => mb_substr(trim((string) ($x["outcome"] ?? "")), 0, 5000), "count_value" => $counted ? $count : null];
}

/** Whether a plan's progress is anything more than an untouched To do. */
function drHasProgress($progress)
{
    return $progress !== null && ($progress["status"] !== "todo" || $progress["outcome"] !== "" || $progress["count_value"] !== null);
}

function drCheckDepartment(PDO $pdo, $departmentId)
{
    $departmentId = (int) $departmentId;
    if (!$departmentId) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM departments WHERE id = ? LIMIT 1");
    $stmt->execute([$departmentId]);
    if (!$stmt->fetchColumn()) {
        throw new PortalWriteError("That department doesn't exist.");
    }
    return $departmentId;
}

/** A report's title: optional, one line, up to DR_MAX_TITLE characters. Null when blank. */
function drCleanTitle($title)
{
    if ($title !== null && !is_string($title)) {
        throw new PortalWriteError("The title must be text.");
    }
    $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
    if (mb_strlen($title) > DR_MAX_TITLE) {
        throw new PortalWriteError("Keep the title to " . DR_MAX_TITLE . " characters.");
    }
    return $title !== "" ? $title : null;
}

function drInsertActivities(PDO $pdo, $reportId, array $acts, $startOrder = 0)
{
    $insert = $pdo->prepare("
        INSERT INTO daily_report_activities (report_id, sort_order, title, description, origin, count_unit, status, outcome, count_value, done_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'done', NOW(), NULL))
    ");
    foreach (array_values($acts) as $i => $a) {
        $g = $a["progress"] ?? null;
        $status = $g ? $g["status"] : "todo";
        $insert->execute([(int) $reportId, $startOrder + $i, $a["title"], $a["description"], $a["origin"], $a["count_unit"],
            $status, $g && $g["outcome"] !== "" ? $g["outcome"] : null, $g ? $g["count_value"] : null, $status]);
    }
}

/** An untouched activity's progress, from your own plan (inside the caller's transaction). */
function drWriteProgress(PDO $pdo, $reportId, $activityId, array $g)
{
    $pdo->prepare("
        UPDATE daily_report_activities
        SET status = ?, outcome = ?, count_value = ?, done_at = IF(? = 'done', NOW(), NULL)
        WHERE id = ? AND report_id = ?
    ")->execute([$g["status"], $g["outcome"] !== "" ? $g["outcome"] : null, $g["count_value"], $g["status"], (int) $activityId, (int) $reportId]);
}

/**
 * Plans a report dated today: for the people in $in["admin_ids"]
 * (reports.create), or for $p themselves ($in["self"], reports.self), whatever
 * else they already have. Your own may carry each activity's progress, and
 * its reviewers hear of it at once. Returns [ids, message].
 * With $in["id"], changes that report's plan instead (drReplan()).
 */
function drPlan(PDO $pdo, array $p, array $in)
{
    if (!empty($in["id"])) {
        return drReplan($pdo, $p, (int) $in["id"], $in);
    }

    $self = !empty($in["self"]);
    if (!can($p, $self ? "reports.self" : "reports.create", $pdo)) {
        throw new PortalWriteError("You can't plan this report.", 403);
    }
    $today = drToday($pdo);
    $title = drCleanTitle($in["title"] ?? null);
    $acts = drCleanPlan($in["activities"] ?? [], $self);
    $started = count(array_filter($acts, function ($a) { return drHasProgress($a["progress"]); })) > 0;
    $deptId = drCheckDepartment($pdo, $in["department_id"] ?? null);
    $tplId = (int) ($in["template_id"] ?? 0) ?: null;

    if ($self) {
        $ids = [(int) $p["id"]];
    } else {
        $ids = array_values(array_unique(array_filter(array_map("intval", (array) ($in["admin_ids"] ?? [])))));
        if (!$ids) {
            throw new PortalWriteError("Pick who fills it in.");
        }
        $allowed = array_column(drPlannable($pdo, $p), "id");
        foreach ($ids as $id) {
            if (!in_array($id, $allowed, true)) {
                throw new PortalWriteError("You can only plan for people on your team.", 403);
            }
        }
    }
    // Your own: the people you picked. Planned for someone: you review it.
    $reviewerIds = $self ? drCheckReviewers($pdo, $in["reviewer_ids"] ?? [], (int) $p["id"]) : [(int) $p["id"]];

    $deptOf = $pdo->prepare("SELECT department_id FROM admins WHERE id = ?");
    $made = [];
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO daily_reports (admin_id, created_by, reviewer_admin_id, department_id, template_id, title, report_date, status, started, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'planned', ?, NOW())
        ");
        foreach ($ids as $id) {
            $dept = $deptId;
            if (!$dept) {
                $deptOf->execute([$id]);
                $dept = $deptOf->fetchColumn() ?: null;
            }
            $insert->execute([$id, (int) $p["id"], $reviewerIds[0], $dept, $tplId, $title, $today, $started ? 1 : 0]);
            $rid = (int) $pdo->lastInsertId();
            drSetReviewers($pdo, $rid, $reviewerIds);
            drInsertActivities($pdo, $rid, $acts);
            $made[] = $rid;
        }
        $who = $self ? "their own activity report" : "the activity report for " . eventAdminNames($pdo, $ids);
        logEvent($pdo, EVENT_REPORT_PLANNED, "daily_report", $made[0], null, "Planned " . $who . " on " . $today);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($self) {
        drNotifyReviewersOfOwn($pdo, $p, $made[0], true);
    } else {
        foreach ($made as $i => $rid) {
            notify($pdo, "admin", $ids[$i], NOTIFY_DAILY_PLANNED, $p["name"] . " planned your day",
                count($acts) . " activit" . (count($acts) === 1 ? "y" : "ies") . ". " . $p["name"] . " reviews it.",
                drLink($rid), "admin", (int) $p["id"]);
        }
    }

    $message = $self ? "Your report is saved, and its reviewers have been told."
        : (count($ids) === 1 ? eventAdminName($pdo, $ids[0]) . " has" : count($ids) . " people have") . " been sent their report.";
    return [$made, $message];
}

/**
 * Edit plan: safe changes only. Matched by id; an activity that was worked
 * on or reviewed keeps its title, description and progress and can't be
 * removed. New activities are added; untouched ones can change.
 */
function drReplan(PDO $pdo, array $p, $id, array $in)
{
    $r = drFind($pdo, $id);
    if (!$r || !drCanSee($pdo, $p, $r) || !drCanPlan($p, $r)) {
        throw new PortalWriteError("This report can't be changed any more.", 403);
    }
    // Your own report: the reviewers you pick now, and progress on untouched
    // activities. Someone else's: the planner, unchanged, and no progress.
    $self = $r["admin_id"] === (int) $p["id"];
    $acts = drCleanPlan($in["activities"] ?? [], $self);
    $reviewerIds = $self ? drCheckReviewers($pdo, $in["reviewer_ids"] ?? [], (int) $p["id"]) : null;
    $deptId = drCheckDepartment($pdo, $in["department_id"] ?? null);
    $tplId = (int) ($in["template_id"] ?? 0) ?: null;
    // Left out (older pages, the AI tools) keeps the title it has.
    $title = array_key_exists("title", $in) ? drCleanTitle($in["title"]) : ($r["title"] !== "" ? $r["title"] : null);

    // Activities the author added themselves aren't the planner's to change:
    // they're left out of the comparison, never deleted, and kept after the plan.
    $byId = [];
    $own = [];
    foreach ($r["activities"] as $a) {
        if ($a["origin"] === "employee") {
            $own[$a["id"]] = $a;
        } else {
            $byId[$a["id"]] = $a;
        }
    }
    $acts = array_values(array_filter($acts, function ($a) use ($own) { return !isset($own[$a["id"]]); }));
    if (!$acts) {
        throw new PortalWriteError("Add at least one activity.");
    }
    if (count($acts) + count($own) > DR_MAX_ACTIVITIES) {
        throw new PortalWriteError("A report can have up to " . DR_MAX_ACTIVITIES . " activities, counting the ones " . $r["admin_name"] . " added.");
    }
    $sent = [];
    foreach ($acts as $a) {
        if (!$a["id"]) {
            continue;
        }
        $was = $byId[$a["id"]] ?? null;
        if (!$was) {
            throw new PortalWriteError("An activity on this plan no longer exists. Reload the page and try again.", 409);
        }
        if (drTouched($was) && $a["title"] !== $was["title"]) {
            throw new PortalWriteError("“" . $was["title"] . "” has been worked on, so its title can't change.");
        }
        $sent[$a["id"]] = true;
    }
    foreach ($byId as $a) {
        if (!isset($sent[$a["id"]]) && drTouched($a)) {
            throw new PortalWriteError("“" . $a["title"] . "” has been worked on, so it can't be removed.");
        }
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE daily_report_activities SET title = ?, description = ?, count_unit = ?, sort_order = ? WHERE id = ? AND report_id = ?");
        $move = $pdo->prepare("UPDATE daily_report_activities SET sort_order = ? WHERE id = ? AND report_id = ?");
        $started = false;
        foreach ($acts as $i => $a) {
            $started = $started || drHasProgress($a["progress"]);
            if ($a["id"]) {
                if (drTouched($byId[$a["id"]])) {
                    $move->execute([$i, $a["id"], $r["id"]]);
                } else {
                    $update->execute([$a["title"], $a["description"], $a["count_unit"], $i, $a["id"], $r["id"]]);
                    if ($a["progress"]) {
                        drWriteProgress($pdo, $r["id"], $a["id"], $a["progress"]);
                    }
                }
            } else {
                drInsertActivities($pdo, $r["id"], [$a], $i);
            }
        }
        $gone = array_diff(array_keys($byId), array_keys($sent));
        if ($gone) {
            $pdo->prepare("DELETE FROM daily_report_activities WHERE report_id = ? AND id IN (" . implode(",", array_map("intval", $gone)) . ")")
                ->execute([$r["id"]]);
        }
        foreach (array_keys($own) as $k => $ownId) {
            $move->execute([count($acts) + $k, $ownId, $r["id"]]);
        }
        $pdo->prepare("UPDATE daily_reports SET department_id = ?, template_id = ?, title = ?, started = started OR ?, updated_at = NOW() WHERE id = ?")
            ->execute([$deptId, $tplId, $title, $started ? 1 : 0, $r["id"]]);
        if ($reviewerIds !== null) {
            drSetReviewers($pdo, $r["id"], $reviewerIds);
        }
        logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"],
            "Changed the activity report for " . $r["admin_name"] . " on " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($self) {
        drNotifyReviewersOfOwn($pdo, $p, $r["id"], false, array_values(array_diff($r["reviewer_ids"], $reviewerIds)));
    } else {
        notifyReplacing($pdo, "admin", $r["admin_id"], NOTIFY_DAILY_PLANNED, $p["name"] . " changed your activity report",
            count($acts) . " activit" . (count($acts) === 1 ? "y" : "ies") . " now. Work you've done stays as it is.",
            drLink($r["id"]), "admin", (int) $p["id"]);
    }

    return [[$r["id"]], $self ? "Your report is updated, and its reviewers have been told." : "Report updated. " . $r["admin_name"] . " sees the change."];
}

/**
 * The author adds an activity of their own to a report someone else planned
 * (drCanAddOwn). $in: {title, description, count_unit}. Returns a message.
 */
function drAddOwnActivity(PDO $pdo, array $p, $id, array $in)
{
    $r = drOwn($pdo, $p, $id);
    if (!drCanAddOwn($pdo, $p, $r)) {
        throw new PortalWriteError("You can't add activities to this report.", 403);
    }
    if (count($r["activities"]) >= DR_MAX_ACTIVITIES) {
        throw new PortalWriteError("A report can have up to " . DR_MAX_ACTIVITIES . " activities.");
    }
    if (trim((string) ($in["title"] ?? "")) === "") {
        throw new PortalWriteError("Give it a title.");
    }
    $act = drCleanPlan([$in])[0];
    $act["origin"] = "employee";

    $next = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM daily_report_activities WHERE report_id = ?");
    $next->execute([$r["id"]]);

    $pdo->beginTransaction();
    try {
        drInsertActivities($pdo, $r["id"], [$act], (int) $next->fetchColumn());
        $pdo->prepare("UPDATE daily_reports SET updated_at = NOW() WHERE id = ?")->execute([$r["id"]]);
        logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"],
            "Added “" . $act["title"] . "” to their activity report for " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return "Activity added.";
}

/** Takes back an activity the author added, before any work on it. Returns a message. */
function drRemoveOwnActivity(PDO $pdo, array $p, $id, $activityId)
{
    $r = drOwn($pdo, $p, $id);
    if (!drCanAddOwn($pdo, $p, $r)) {
        throw new PortalWriteError("This report can't be changed any more.", 403);
    }
    $act = null;
    foreach ($r["activities"] as $a) {
        if ($a["id"] === (int) $activityId) {
            $act = $a;
        }
    }
    if (!$act || $act["origin"] !== "employee") {
        throw new PortalWriteError("Only activities you added can be removed.", 403);
    }
    if (drTouched($act)) {
        throw new PortalWriteError("“" . $act["title"] . "” has been worked on, so it can't be removed.");
    }
    if (count($r["activities"]) < 2) {
        throw new PortalWriteError("A report needs at least one activity.");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM daily_report_activities WHERE id = ? AND report_id = ?")->execute([$act["id"], $r["id"]]);
        $pdo->prepare("UPDATE daily_reports SET updated_at = NOW() WHERE id = ?")->execute([$r["id"]]);
        logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"],
            "Removed “" . $act["title"] . "” from their activity report for " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return "Activity removed.";
}

/** The author's own report, for a write by them. */
function drOwn(PDO $pdo, array $p, $id)
{
    $r = drFind($pdo, $id);
    if (!$r || $r["admin_id"] !== (int) $p["id"]) {
        throw new PortalWriteError("This report isn't yours to change.", 403);
    }
    return $r;
}

/**
 * The author's in-place edits: [{id, status, outcome, count_value}], only the
 * changed ones. Until the report is sent, anything not reviewed; after that,
 * only activities sent back. Returns a message.
 */
function drUpdateActivities(PDO $pdo, array $p, $id, $edits)
{
    [$r, $open, $clean] = drCheckActivityEdits($pdo, $p, $id, $edits);
    return drSaveActivityEdits($pdo, $p, $r, $open, $clean);
}

/**
 * The checks of drUpdateActivities(), nothing written - also what the MCP's
 * update_report_activity prepares with. Returns [report, open, clean edits],
 * each clean edit [activity, status, outcome, count].
 */
function drCheckActivityEdits(PDO $pdo, array $p, $id, $edits)
{
    $r = drOwn($pdo, $p, $id);
    $open = drCanFill($p, $r);
    if (!$open && !drCanFix($p, $r)) {
        throw new PortalWriteError($r["status"] === "reviewed" ? "It has been reviewed, so it can't change." : "This report is locked.");
    }
    if (!is_array($edits) || !$edits) {
        throw new PortalWriteError("Nothing to save.");
    }

    $byId = [];
    foreach ($r["activities"] as $a) {
        $byId[$a["id"]] = $a;
    }
    $clean = [];
    foreach ($edits as $e) {
        $a = $byId[(int) ($e["id"] ?? 0)] ?? null;
        if (!$a) {
            throw new PortalWriteError("That activity isn't on this report.");
        }
        if ($a["review"] && $a["review"]["state"] === "approved") {
            throw new PortalWriteError("“" . $a["title"] . "” has been reviewed, so it can't change.");
        }
        if (!$open && !drSentBack($a)) {
            throw new PortalWriteError("The report is locked. Only activities sent back for changes can be edited.");
        }
        $status = (string) ($e["status"] ?? "");
        if (!in_array($status, DR_ACTIVITY_STATUSES, true)) {
            throw new PortalWriteError("Pick a status for “" . $a["title"] . "”.");
        }
        $count = $e["count_value"] ?? null;
        if ($count === "" || $count === null) {
            $count = null;
        } elseif (!is_numeric($count) || (int) $count != $count || (int) $count < 0) {
            throw new PortalWriteError("Counts are whole numbers, 0 or more.");
        } else {
            $count = (int) $count;
        }
        $clean[] = [$a, $status, mb_substr(trim((string) ($e["outcome"] ?? "")), 0, 5000), $a["count_unit"] ? $count : null];
    }
    return [$r, $open, $clean];
}

function drSaveActivityEdits(PDO $pdo, array $p, array $r, $open, array $clean)
{

    $pdo->beginTransaction();
    try {
        // done_at first: MySQL applies SET left to right, so `status` here is
        // still the saved one. Done again keeps the time it was first done.
        $update = $pdo->prepare("
            UPDATE daily_report_activities
            SET done_at = CASE WHEN ? = 'done' THEN IF(status = 'done' AND done_at IS NOT NULL, done_at, NOW()) ELSE NULL END,
                status = ?, outcome = ?, count_value = ?
            WHERE id = ? AND report_id = ?
        ");
        foreach ($clean as [$a, $status, $outcome, $count]) {
            $update->execute([$status, $status, $outcome, $count, $a["id"], $r["id"]]);
        }
        $pdo->prepare("UPDATE daily_reports SET started = 1, updated_at = NOW() WHERE id = ?")->execute([$r["id"]]);
        if ($r["status"] !== "planned") {
            logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"], "Edited the activity report for " . $r["report_date"]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $after = drFind($pdo, $r["id"], false);
    $waiting = 0;
    foreach ($after["activities"] as $a) {
        $waiting += drAwaiting($a) ? 1 : 0;
    }

    // Sent-back work done again on a sent report: the reviewer wouldn't
    // otherwise know to look. (While it's open, Done work just appears.)
    $fixed = array_filter($clean, function ($c) { return drSentBack($c[0]) && $c[1] === "done"; });
    if (!$open && $fixed) {
        foreach ($r["reviewer_ids"] as $reviewerId) {
            notifyReplacing($pdo, "admin", $reviewerId, NOTIFY_DAILY_REPORT, $r["admin_name"] . " updated what you sent back",
                "Their report for " . $r["report_date"] . ": " . count($fixed) . " activit" . (count($fixed) === 1 ? "y" : "ies") . " ready to review again.",
                drLink($r["id"]), "admin", (int) $p["id"]);
        }
    }
    return (count($clean) === 1 ? "Activity saved." : count($clean) . " activities saved.")
        . ($waiting && $r["reviewer_name"] ? " " . $r["reviewer_name"] . " can review the done ones now." : "");
}

/**
 * The end-of-day note, checked: outstanding work, tomorrow's priorities, and
 * challenges or assistance required with how urgent (only with challenges).
 */
function drCleanEndOfDay(array $in)
{
    $challenges = mb_substr(trim((string) ($in["challenges"] ?? "")), 0, 5000);
    $priority = (string) ($in["challenge_priority"] ?? "");
    if ($challenges !== "" && !in_array($priority, DR_CHALLENGE_PRIORITIES, true)) {
        throw new PortalWriteError("Say how urgent the challenge is: low, medium or high.");
    }
    return [
        "outstanding" => mb_substr(trim((string) ($in["outstanding"] ?? "")), 0, 5000),
        "priorities" => mb_substr(trim((string) ($in["priorities"] ?? "")), 0, 5000),
        "challenges" => $challenges,
        "challenge_priority" => $challenges !== "" ? $priority : null,
    ];
}

function drWriteEndOfDay(PDO $pdo, $reportId, array $eod)
{
    $pdo->prepare("UPDATE daily_reports SET outstanding = ?, priorities = ?, challenges = ?, challenge_priority = ?, started = 1, updated_at = NOW() WHERE id = ?")
        ->execute([$eod["outstanding"] ?: null, $eod["priorities"] ?: null, $eod["challenges"] ?: null, $eod["challenge_priority"], (int) $reportId]);
}

/** The author saves the end-of-day note while the report is open. Returns a message. */
function drSaveEndOfDay(PDO $pdo, array $p, $id, array $in)
{
    $r = drOwn($pdo, $p, $id);
    if (!drCanFill($p, $r)) {
        throw new PortalWriteError("It's sent, so this can't change.");
    }
    drWriteEndOfDay($pdo, $r["id"], drCleanEndOfDay($in));
    return "End of day saved.";
}

/**
 * Sending: the author sends an open report, from any day, with the end-of-day
 * note when $in carries one. Returns [report, message].
 */
function drClose(PDO $pdo, array $p, $id, array $in = [])
{
    $r = drOwn($pdo, $p, $id);
    if ($r["status"] !== "planned") {
        throw new PortalWriteError("It's already sent.");
    }
    $eod = array_key_exists("outstanding", $in) || array_key_exists("priorities", $in) || array_key_exists("challenges", $in)
        ? drCleanEndOfDay($in) : null;

    $pdo->beginTransaction();
    try {
        if ($eod) {
            drWriteEndOfDay($pdo, $r["id"], $eod);
        }
        $stmt = $pdo->prepare("UPDATE daily_reports SET status = 'submitted', started = 1, submitted_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'planned'");
        $stmt->execute([$r["id"]]);
        if (!$stmt->rowCount()) {
            throw new PortalWriteError("It's already sent.", 409);
        }
        logEvent($pdo, EVENT_REPORT_CLOSED, "daily_report", $r["id"], $r["admin_name"], "Sent the activity report for " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $waiting = count(array_filter($r["activities"], "drAwaiting"));
    $priority = $eod ? $eod["challenge_priority"] : $r["challenge_priority"];
    $help = $priority === "high" ? " Needs help: a high-priority challenge." : "";
    foreach ($r["reviewer_ids"] as $reviewerId) {
        notifyReplacing($pdo, "admin", $reviewerId, NOTIFY_DAILY_REPORT, $r["admin_name"] . " sent their activity report",
            drProgressText($r) . ($waiting ? ", " . $waiting . " waiting for your review." : ".") . $help,
            drLink($r["id"]), "admin", (int) $p["id"]);
    }
    return [$r, "Report sent to " . ($r["reviewer_name"] ?: "your reviewer") . ". They've been told it's ready."];
}

/**
 * Files on the author's report: the ones kept plus new uploads, while it's
 * open or while fixing sent-back work. Returns the paths to unlink after.
 */
function drUpdateFiles(PDO $pdo, array $p, $id, $keepIds, array $files)
{
    $r = drOwn($pdo, $p, $id);
    if (!drCanFill($p, $r) && !drCanFix($p, $r)) {
        throw new PortalWriteError($r["status"] === "reviewed" ? "It has been reviewed, so it can't change." : "This report is locked.");
    }
    $pdo->beginTransaction();
    try {
        $gone = saveAttachments($pdo, DAILY_REPORT_FILES_OWNER, $r["id"], is_array($keepIds) ? $keepIds : [], $files, (int) $p["id"]);
        $pdo->prepare("UPDATE daily_reports SET started = 1, updated_at = NOW() WHERE id = ?")->execute([$r["id"]]);
        if ($r["status"] !== "planned") {
            logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"], "Changed the files on the activity report for " . $r["report_date"]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $gone;
}

/** Keeps the latest review on the row and moves the one it replaces to the history table. */
function drSetReview(PDO $pdo, array $a, $state, $feedback, $byId)
{
    if ($a["review"]) {
        $pdo->prepare("INSERT INTO daily_report_activity_reviews (activity_id, state, feedback, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))")
            ->execute([$a["id"], $a["review"]["state"], $a["review"]["feedback"], $a["review"]["by"], $a["review"]["at"]]);
    }
    $reopen = $state === "changes";
    $pdo->prepare("
        UPDATE daily_report_activities
        SET review_state = ?, review_feedback = ?, reviewed_by = ?, reviewed_at = NOW()"
        . ($reopen ? ", status = 'in_progress', done_at = NULL" : "") . "
        WHERE id = ?
    ")->execute([$state, $feedback, (int) $byId, $a["id"]]);
}

/**
 * One activity reviewed: approved (feedback optional) or sent back for
 * changes (feedback required; the activity reopens). Returns [report, activity, message].
 */
function drReviewActivity(PDO $pdo, array $p, $activityId, $state, $feedback)
{
    $stmt = $pdo->prepare("SELECT report_id FROM daily_report_activities WHERE id = ?");
    $stmt->execute([(int) $activityId]);
    $rid = (int) $stmt->fetchColumn();
    $r = $rid ? drFind($pdo, $rid, false) : null;
    if (!$r || !drCanSee($pdo, $p, $r)) {
        throw new PortalWriteError("That activity doesn't exist, or it isn't one you can see.", 404);
    }
    if (!drCanReviewActivities($pdo, $p, $r)) {
        throw new PortalWriteError("You can't review this report.", 403);
    }
    $a = null;
    foreach ($r["activities"] as $x) {
        if ($x["id"] === (int) $activityId) {
            $a = $x;
        }
    }
    if (!$a || !drAwaiting($a)) {
        throw new PortalWriteError("Only activities saved as Done and not reviewed yet can be reviewed.");
    }
    $state = $state === "changes" ? "changes" : "approved";
    $feedback = mb_substr(trim((string) $feedback), 0, 2000);
    if ($state === "changes" && $feedback === "") {
        throw new PortalWriteError("Say what to change.");
    }

    $pdo->beginTransaction();
    try {
        drSetReview($pdo, $a, $state, $feedback, $p["id"]);
        logEvent($pdo, EVENT_REPORT_ACTIVITY_REVIEWED, "daily_report", $r["id"], $r["admin_name"],
            ($state === "changes" ? "Sent back \"" : "Reviewed \"") . $a["title"] . "\" for " . $r["admin_name"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    drNotifyActivityReviews($pdo, $p, $r["id"]);

    $message = $state === "changes"
        ? "Sent back to " . $r["admin_name"] . " with your feedback."
        : "Marked as reviewed." . ($feedback !== "" ? " " . $r["admin_name"] . " sees your feedback." : "");
    return [$r, $a + ["new_state" => $state], $message];
}

/**
 * The whole report reviewed, once sent: remaining Done activities are
 * approved with it; refused while anything waits on changes. Returns [report, message].
 */
function drReviewReport(PDO $pdo, array $p, $id, $note)
{
    $r = drFind($pdo, $id, false);
    if (!$r || !drCanSee($pdo, $p, $r)) {
        throw new PortalWriteError("This report doesn't exist, or it isn't one you can see.", 404);
    }
    if (drAnySentBack($r)) {
        throw new PortalWriteError("An activity is waiting on changes. Review the report once it's done again.");
    }
    if (!drCanReviewReport($pdo, $p, $r)) {
        throw new PortalWriteError("You can't review this report yet.", 403);
    }
    $bulk = array_values(array_filter($r["activities"], "drAwaiting"));

    $pdo->beginTransaction();
    try {
        foreach ($bulk as $a) {
            drSetReview($pdo, $a, "approved", "", $p["id"]);
        }
        $stmt = $pdo->prepare("
            UPDATE daily_reports SET status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), reviewer_note = ?, updated_at = NOW()
            WHERE id = ? AND status = 'submitted'
        ");
        $stmt->execute([(int) $p["id"], mb_substr(trim((string) $note), 0, 2000), $r["id"]]);
        if (!$stmt->rowCount()) {
            throw new PortalWriteError("Someone else has just reviewed it.", 409);
        }
        logEvent($pdo, EVENT_REPORT_REVIEWED, "daily_report", $r["id"], $r["admin_name"],
            "Reviewed " . $r["admin_name"] . "'s activity report for " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $n = count($bulk);
    $note = trim((string) $note);
    // The report's review says it all: the merged activity row goes.
    try {
        $pdo->prepare("DELETE FROM notifications WHERE recipient_kind = 'admin' AND recipient_id = ? AND event_type = ? AND link = ? AND read_at IS NULL")
            ->execute([$r["admin_id"], NOTIFY_DAILY_ACTIVITY_REVIEWED, drLink($r["id"])]);
        // Any one reviewer completes it: the others' "ready for you" rows go.
        $others = array_values(array_diff($r["reviewer_ids"], [(int) $p["id"]]));
        if ($others) {
            $pdo->prepare("DELETE FROM notifications WHERE recipient_kind = 'admin' AND event_type = ? AND link = ? AND read_at IS NULL
                           AND recipient_id IN (" . implode(",", array_map("intval", $others)) . ")")
                ->execute([NOTIFY_DAILY_REPORT, drLink($r["id"])]);
        }
    } catch (Throwable $e) {
        // A leftover row is harmless.
    }
    notify($pdo, "admin", $r["admin_id"], NOTIFY_DAILY_REPORT_REVIEWED, $p["name"] . " reviewed your activity report",
        $note !== "" ? mb_substr($note, 0, 400) : "Your report for " . $r["report_date"] . " is reviewed and locked.",
        drLink($r["id"]), "admin", (int) $p["id"]);

    return [$r, "Report reviewed" . ($n ? ", with " . $n . " more activit" . ($n === 1 ? "y" : "ies") : "") . ". " . $r["admin_name"] . " has been told."];
}

/** Deletes a report nobody has started: its planner only. Returns [report, message, file paths to unlink]. */
function drDelete(PDO $pdo, array $p, $id)
{
    $r = drFind($pdo, $id, false);
    if (!$r || !drCanSee($pdo, $p, $r)) {
        throw new PortalWriteError("This report doesn't exist, or it isn't one you can see.", 404);
    }
    if ($r["created_by"] !== (int) $p["id"]) {
        throw new PortalWriteError("Only the person who planned it can delete it.", 403);
    }
    if (!drCanDelete($p, $r)) {
        throw new PortalWriteError("Work has started on it, so it can't be deleted.");
    }

    $pdo->beginTransaction();
    try {
        $paths = deleteAttachmentRows($pdo, DAILY_REPORT_FILES_OWNER, $r["id"]);
        $pdo->prepare("DELETE h FROM daily_report_activity_reviews h JOIN daily_report_activities x ON x.id = h.activity_id WHERE x.report_id = ?")->execute([$r["id"]]);
        $pdo->prepare("DELETE FROM daily_report_activities WHERE report_id = ?")->execute([$r["id"]]);
        $pdo->prepare("DELETE FROM daily_reports WHERE id = ?")->execute([$r["id"]]);
        logEvent($pdo, EVENT_REPORT_DELETED, "daily_report", $r["id"], $r["admin_name"],
            "Deleted the activity report for " . $r["admin_name"] . " on " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $mine = $r["admin_id"] === (int) $p["id"];
    if (!$mine) {
        // Earlier rows about it would link to a report that's gone.
        try {
            $pdo->prepare("DELETE FROM notifications WHERE recipient_kind = 'admin' AND link = ?")->execute([drLink($r["id"])]);
        } catch (Throwable $e) {
            // Leftovers open a "doesn't exist" page; harmless.
        }
        notify($pdo, "admin", $r["admin_id"], NOTIFY_DAILY_REPORT_DELETED, $p["name"] . " withdrew your activity report",
            "Your report for " . $r["report_date"] . ": you don't need to fill it in.", "activity-reports.html", "admin", (int) $p["id"]);
    }
    return [$r, $mine ? "Your report is deleted." : $r["admin_name"] . "'s report is deleted. They've been told.", $paths];
}

/** Creates or changes a template (reports.create; changes by its maker or the Director). Returns [id, message]. */
function drTemplateSave(PDO $pdo, array $p, array $in)
{
    if (!can($p, "reports.create", $pdo)) {
        throw new PortalWriteError("You can't manage templates.", 403);
    }
    $id = (int) ($in["id"] ?? 0);
    $tpl = $id ? (drTemplates($pdo, $p, $id)[0] ?? null) : null;
    if ($id && !$tpl) {
        throw new PortalWriteError("That template doesn't exist.", 404);
    }
    if ($tpl && !$tpl["can_edit"]) {
        throw new PortalWriteError("Only " . $tpl["created_by_name"] . " or the Director can change this template.", 403);
    }
    $name = mb_substr(trim((string) ($in["name"] ?? "")), 0, 80);
    if ($name === "") {
        throw new PortalWriteError("Give the template a name.");
    }
    $acts = array_map(function ($a) {
        return ["title" => $a["title"], "description" => $a["description"], "count_unit" => $a["count_unit"]];
    }, drCleanPlan($in["activities"] ?? []));
    // Templates no longer carry a reviewer: whoever plans the report reviews it.
    $reviewer = null;
    $dept = drCheckDepartment($pdo, $in["department_id"] ?? null);
    $json = json_encode($acts, JSON_UNESCAPED_UNICODE);

    if ($tpl) {
        $pdo->prepare("UPDATE daily_report_templates SET name = ?, department_id = ?, reviewer_admin_id = ?, activities = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$name, $dept, $reviewer, $json, $id]);
    } else {
        $pdo->prepare("INSERT INTO daily_report_templates (name, created_by, department_id, reviewer_admin_id, activities, updated_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$name, (int) $p["id"], $dept, $reviewer, $json]);
        $id = (int) $pdo->lastInsertId();
    }
    logEvent($pdo, EVENT_REPORT_TEMPLATE_SAVED, "daily_report_template", $id, $name, "Saved the template \"" . $name . "\"");
    return [$id, "Template saved."];
}

function drTemplateDelete(PDO $pdo, array $p, $id)
{
    if (!can($p, "reports.create", $pdo)) {
        throw new PortalWriteError("You can't manage templates.", 403);
    }
    $tpl = drTemplates($pdo, $p, (int) $id)[0] ?? null;
    if (!$tpl) {
        throw new PortalWriteError("That template doesn't exist.", 404);
    }
    if (!$tpl["can_edit"]) {
        throw new PortalWriteError("Only " . $tpl["created_by_name"] . " or the Director can change this template.", 403);
    }
    $pdo->prepare("UPDATE daily_report_templates SET active = 0, updated_at = NOW() WHERE id = ?")->execute([(int) $id]);
    logEvent($pdo, EVENT_REPORT_TEMPLATE_DELETED, "daily_report_template", (int) $id, $tpl["name"], "Deleted the template \"" . $tpl["name"] . "\"");
    return "Template deleted. Reports already planned from it stay as they are.";
}
