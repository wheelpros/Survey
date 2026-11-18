<?php

/*
|--------------------------------------------------------------------------
| Daily reports: the rules, in one place
|--------------------------------------------------------------------------
|
| A planner writes the day's activities for someone: reports.create for their
| team (everyone, for the Director and reports.view_all), reports.self for
| their own report. The person fills it in on the report page through the
| day - each activity's status, outcome and count, plus files - and closes it
| at the end of the day. The reviewer (one person: the Director or an Account
| Manager) reviews each Done activity as soon as it's saved - Reviewed, or
| Needs changes with feedback - and the whole report once it's closed.
|
| The rules, as the audit left them (docs: the fix plan of 2026-11-19):
|
|   - "Today" is the portal's day (portalTimezone()), never the browser's.
|   - The author edits their report while it's today's and not closed. After
|     that only activities sent back for changes - on ANY day, so a send-back
|     never leaves a report stuck.
|   - Edit plan: only while the report is today's and not closed, and only
|     safe changes. Activities are matched by id; one that was worked on or
|     reviewed can't be removed or retitled.
|   - Delete: the planner, only before anything happened (today's, not
|     started). A missed report stays on record.
|   - Reviewing: the report's reviewer, the Director, or reports.review for
|     their own team. reports.view_all sees every report but reviews none by
|     itself. Nobody reviews their own report.
|
| Used by api/daily-reports.php (reads), api/daily-report-writes.php (writes),
| api/admin-dashboard.php (the cards) and api/v1 (the MCP tools), so every way
| in follows the same rules. Refusals throw PortalWriteError (db.php).
|
| Callers run ensureDailyReportTables() first: it is DDL, which can't happen
| inside the transactions below.
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/team-scope.php";
require_once __DIR__ . "/upload-helpers.php";
require_once __DIR__ . "/availability.php"; // portalTimezone()

const DR_ACTIVITY_STATUSES = ["todo", "in_progress", "follow_up", "done"];
const DR_MAX_ACTIVITIES = 30;
const DR_LIST_PAGE_MAX = 100;

/* Effective statuses, as the lists sort them (most in need of someone first). */
const DR_STATUS_RANK = ["awaiting" => 0, "changes" => 1, "sent" => 2, "in_progress" => 3, "planned" => 4, "missing" => 5, "reviewed" => 6];

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
    return $p["role"] === "owner" || can($p, "reports.view_all", $pdo);
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
    return drSeesAll($pdo, $p) || $r["admin_id"] === $me || $r["reviewer_id"] === $me || $r["created_by"] === $me
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
function drEffectiveStatus(array $r, $today)
{
    if ($r["status"] === "planned" && $r["report_date"] < $today) {
        return "missing";
    }
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

/** The author, while it's open: today's and not closed. */
function drCanFill(array $p, array $r, $today)
{
    return $r["admin_id"] === (int) $p["id"] && $r["status"] === "planned" && $r["report_date"] === $today;
}

/** The author fixing what was sent back, once the report is closed or its day has passed. */
function drCanFix(array $p, array $r, $today)
{
    return $r["admin_id"] === (int) $p["id"] && $r["status"] !== "reviewed" && !drCanFill($p, $r, $today) && drAnySentBack($r);
}

function drCanPlan(array $p, array $r, $today)
{
    return $r["created_by"] === (int) $p["id"] && $r["status"] === "planned" && $r["report_date"] === $today;
}

function drCanDelete(array $p, array $r, $today)
{
    return $r["created_by"] === (int) $p["id"] && drEffectiveStatus($r, $today) === "planned";
}

function drCanReviewActivities(PDO $pdo, array $p, array $r)
{
    $me = (int) $p["id"];
    if ($r["status"] === "reviewed" || $r["admin_id"] === $me) {
        return false;
    }
    return $r["reviewer_id"] === $me || $p["role"] === "owner" || drReviewsTeam($pdo, $p, $r["admin_id"]);
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
        $out[] = ["id" => $id, "name" => $x["name"], "role" => $x["role"], "position" => $x["position"],
            "department_id" => $x["department_id"] !== null ? (int) $x["department_id"] : null,
            "department_name" => $x["department_name"]];
    }
    return $out;
}

/** Who a report can go to: the Director and active Account Managers. */
function drReviewers(PDO $pdo)
{
    $rows = $pdo->query("
        SELECT id, name, role FROM admins
        WHERE active = 1 AND role IN ('owner', 'account_manager')
        ORDER BY role = 'owner' DESC, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function ($x) {
        return ["id" => (int) $x["id"], "name" => $x["name"], "role" => $x["role"]];
    }, $rows);
}

function drCheckReviewer(PDO $pdo, $reviewerId, array $forIds)
{
    $reviewerId = (int) $reviewerId;
    $ok = false;
    foreach (drReviewers($pdo) as $r) {
        if ($r["id"] === $reviewerId) {
            $ok = true;
        }
    }
    if (!$ok) {
        throw new PortalWriteError("Reports go to the Director or an Account Manager.");
    }
    if (in_array($reviewerId, array_map("intval", $forIds), true)) {
        throw new PortalWriteError("Pick someone else to review it: " . eventAdminName($pdo, $reviewerId) . " can't review their own report.");
    }
    return $reviewerId;
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
               cb.name AS created_by_name, rv.name AS reviewer_name, rb.name AS reviewed_by_name,
               d.name AS department_name, t.name AS template_name
        FROM daily_reports r
        JOIN admins a ON a.id = r.admin_id
        LEFT JOIN admins cb ON cb.id = r.created_by
        LEFT JOIN admins rv ON rv.id = r.reviewer_admin_id
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
            "reviewer_id" => $row["reviewer_admin_id"] !== null ? (int) $row["reviewer_admin_id"] : null,
            "reviewer_name" => $row["reviewer_name"],
            "department_id" => $row["department_id"] !== null ? (int) $row["department_id"] : null,
            "department_name" => $row["department_name"],
            "template_id" => $row["template_id"] !== null ? (int) $row["template_id"] : null,
            "template_name" => $row["template_name"],
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

/** A report as the lists show it. */
function drSummary(PDO $pdo, array $p, array $r)
{
    $today = drToday($pdo);
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
        "template_name" => $r["template_name"],
        "report_date" => $r["report_date"], "status" => $r["status"], "effective_status" => drEffectiveStatus($r, $today),
        "to_review" => $toReview, "activities_total" => count($r["activities"]), "activities_done" => $done,
        "counts" => drCounts([$r]),
        "reviewer_id" => $r["reviewer_id"], "reviewer_name" => $r["reviewer_name"],
        "reviewed_by" => $r["reviewed_by"], "reviewed_by_name" => $r["reviewed_by_name"], "reviewer_note" => $r["reviewer_note"],
        "reviewed_ts" => $r["reviewed_ts"], "submitted_ts" => $r["submitted_ts"], "updated_ts" => $r["updated_ts"], "created_ts" => $r["created_ts"],
        "file_count" => $r["file_count"],
        "can_plan" => drCanPlan($p, $r, $today), "can_delete" => drCanDelete($p, $r, $today),
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
    return array_merge(drSummary($pdo, $p, $r), ["activities" => $acts, "files" => $r["files"]]);
}

/** The flags the report page needs, beside the report itself. */
function drFlags(PDO $pdo, array $p, array $r)
{
    $today = drToday($pdo);
    $canReviewActivities = drCanReviewActivities($pdo, $p, $r);
    return [
        "can_fill" => drCanFill($p, $r, $today),
        "can_fix" => drCanFix($p, $r, $today),
        "can_plan" => drCanPlan($p, $r, $today),
        "can_delete" => drCanDelete($p, $r, $today),
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
    $parts = ["r.reviewer_admin_id = ?", "r.created_by = ?"];
    if (can($p, "reports.review", $pdo)) {
        $pool = drPool($pdo, $p);
        if ($pool) {
            $parts[] = "r.admin_id IN (" . implode(",", array_map("intval", $pool)) . ")";
        }
    }
    return ["r.admin_id <> ? AND (" . implode(" OR ", $parts) . ")", [$me, $me, $me]];
}

/** How many of $p's reports need them: today's still open, plus any with work sent back to fix. */
function drPendingCount(PDO $pdo, array $p)
{
    $today = drToday($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM daily_reports r
        WHERE r.admin_id = ?
          AND ((r.report_date = ? AND r.status = 'planned')
            OR (r.status <> 'reviewed' AND EXISTS (
                  SELECT 1 FROM daily_report_activities x
                  WHERE x.report_id = r.id AND x.review_state = 'changes' AND x.status <> 'done')))
    ");
    $stmt->execute([(int) $p["id"], $today]);
    return (int) $stmt->fetchColumn();
}

/** Reports addressed to $p with Done activities not reviewed yet. */
function drWaitingForMe(PDO $pdo, array $p)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM daily_reports r
        WHERE r.reviewer_admin_id = ? AND r.status <> 'reviewed' AND EXISTS (
            SELECT 1 FROM daily_report_activities x
            WHERE x.report_id = r.id AND x.status = 'done' AND (x.review_state IS NULL OR x.review_state <> 'approved'))
    ");
    $stmt->execute([(int) $p["id"]]);
    return (int) $stmt->fetchColumn();
}

/**
 * The dashboard's numbers for $p, in one call: their own report today, how
 * many reports wait for their review, and - for whoever sees the team's - the
 * day's reports counted by effective status.
 */
function drSummaryFor(PDO $pdo, array $p, $departmentId = null)
{
    $today = drToday($pdo);
    $mine = drFetch($pdo, "r.admin_id = ? AND r.report_date = ?", [(int) $p["id"], $today]);
    $mine = $mine ? reset($mine) : null;

    $out = [
        "today" => $today,
        "my_report" => $mine ? drSummary($pdo, $p, $mine) : null,
        "pending" => drPendingCount($pdo, $p),
        "waiting_for_me" => drWaitingForMe($pdo, $p),
        "team" => null,
    ];

    if (drSeesAll($pdo, $p) || can($p, "reports.review", $pdo) || can($p, "reports.create", $pdo)) {
        [$scope, $params] = drTeamScopeSql($pdo, $p);
        $where = $scope . " AND r.report_date = ?";
        $params[] = $today;
        if ($departmentId === "none") {
            $where .= " AND r.department_id IS NULL";
        } elseif ($departmentId) {
            $where .= " AND r.department_id = ?";
            $params[] = (int) $departmentId;
        }
        $counts = array_fill_keys(array_keys(DR_STATUS_RANK), 0);
        foreach (drFetch($pdo, $where, $params) as $r) {
            $counts[drEffectiveStatus($r, $today)]++;
        }
        $out["team"] = $counts;
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
    return $p["role"] === "owner" || (int) $t["created_by"] === (int) $p["id"];
}

function drTemplates(PDO $pdo, array $p, $id = null)
{
    $sql = "
        SELECT t.*, d.name AS department_name, rv.name AS reviewer_name, cb.name AS created_by_name
        FROM daily_report_templates t
        LEFT JOIN departments d ON d.id = t.department_id
        LEFT JOIN admins rv ON rv.id = t.reviewer_admin_id
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
            "reviewer_id" => $t["reviewer_admin_id"] !== null ? (int) $t["reviewer_admin_id"] : null,
            "reviewer_name" => $t["reviewer_admin_id"] !== null ? $t["reviewer_name"] : "Whoever plans the report",
            "created_by" => (int) $t["created_by"], "created_by_name" => (string) $t["created_by_name"],
            "active" => (int) $t["active"],
            "can_edit" => drCanEditTemplate($p, $t),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

/** A planner's activities, checked and tidied: [{id, title, description, count_unit, origin}]. */
function drCleanPlan($list)
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

function drInsertActivities(PDO $pdo, $reportId, array $acts, $startOrder = 0)
{
    $insert = $pdo->prepare("
        INSERT INTO daily_report_activities (report_id, sort_order, title, description, origin, count_unit)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach (array_values($acts) as $i => $a) {
        $insert->execute([(int) $reportId, $startOrder + $i, $a["title"], $a["description"], $a["origin"], $a["count_unit"]]);
    }
}

/**
 * Plans today's report: for the people in $in["admin_ids"] (reports.create),
 * or for $p themselves ($in["self"], reports.self). Returns [ids, message].
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
    $acts = drCleanPlan($in["activities"] ?? []);
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
    $reviewerId = drCheckReviewer($pdo, $in["reviewer_id"] ?? 0, $ids);

    $marks = implode(",", array_fill(0, count($ids), "?"));
    $taken = $pdo->prepare("SELECT a.name FROM daily_reports r JOIN admins a ON a.id = r.admin_id WHERE r.report_date = ? AND r.admin_id IN ($marks)");
    $taken->execute(array_merge([$today], $ids));
    $names = $taken->fetchAll(PDO::FETCH_COLUMN);
    if ($names) {
        throw new PortalWriteError($self ? "You already have a report for today."
            : implode(", ", $names) . (count($names) === 1 ? " already has" : " already have") . " a report for today. Open it from Team reports to change it.", 409);
    }

    $deptOf = $pdo->prepare("SELECT department_id FROM admins WHERE id = ?");
    $made = [];
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO daily_reports (admin_id, created_by, reviewer_admin_id, department_id, template_id, report_date, status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'planned', NOW())
        ");
        foreach ($ids as $id) {
            $dept = $deptId;
            if (!$dept) {
                $deptOf->execute([$id]);
                $dept = $deptOf->fetchColumn() ?: null;
            }
            $insert->execute([$id, (int) $p["id"], $reviewerId, $dept, $tplId, $today]);
            $rid = (int) $pdo->lastInsertId();
            drInsertActivities($pdo, $rid, $acts);
            $made[] = $rid;
        }
        $who = $self ? "their own daily report" : "the daily report for " . eventAdminNames($pdo, $ids);
        logEvent($pdo, EVENT_REPORT_PLANNED, "daily_report", $made[0], null, "Planned " . $who . " on " . $today);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && (string) $e->getCode() === "23000") {
            throw new PortalWriteError("Someone already has a report for today. Reload and try again.", 409);
        }
        throw $e;
    }

    $message = $self ? "Your report for today is ready to fill in."
        : (count($ids) === 1 ? eventAdminName($pdo, $ids[0]) . " has" : count($ids) . " people have") . " been sent their report for today.";
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
    $today = drToday($pdo);
    if (!$r || !drCanSee($pdo, $p, $r) || !drCanPlan($p, $r, $today)) {
        throw new PortalWriteError("This report can't be changed any more.", 403);
    }
    $acts = drCleanPlan($in["activities"] ?? []);
    $reviewerId = drCheckReviewer($pdo, $in["reviewer_id"] ?? 0, [$r["admin_id"]]);
    $deptId = drCheckDepartment($pdo, $in["department_id"] ?? null);
    $tplId = (int) ($in["template_id"] ?? 0) ?: null;

    $byId = [];
    foreach ($r["activities"] as $a) {
        $byId[$a["id"]] = $a;
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
    foreach ($r["activities"] as $a) {
        if (!isset($sent[$a["id"]]) && drTouched($a)) {
            throw new PortalWriteError("“" . $a["title"] . "” has been worked on, so it can't be removed.");
        }
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE daily_report_activities SET title = ?, description = ?, count_unit = ?, sort_order = ? WHERE id = ? AND report_id = ?");
        $move = $pdo->prepare("UPDATE daily_report_activities SET sort_order = ? WHERE id = ? AND report_id = ?");
        foreach ($acts as $i => $a) {
            if ($a["id"]) {
                if (drTouched($byId[$a["id"]])) {
                    $move->execute([$i, $a["id"], $r["id"]]);
                } else {
                    $update->execute([$a["title"], $a["description"], $a["count_unit"], $i, $a["id"], $r["id"]]);
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
        $pdo->prepare("UPDATE daily_reports SET reviewer_admin_id = ?, department_id = ?, template_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$reviewerId, $deptId, $tplId, $r["id"]]);
        logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"],
            "Changed the daily report for " . $r["admin_name"] . " on " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [[$r["id"]], $r["admin_id"] === (int) $p["id"] ? "Your report is updated." : "Report updated. " . $r["admin_name"] . " sees the change."];
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
 * changed ones. While the report is open, anything not reviewed; once it's
 * closed or its day has passed, only activities sent back. Returns a message.
 */
function drUpdateActivities(PDO $pdo, array $p, $id, $edits)
{
    $r = drOwn($pdo, $p, $id);
    $today = drToday($pdo);
    $open = drCanFill($p, $r, $today);
    if (!$open && !drCanFix($p, $r, $today)) {
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
            logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"], "Edited the daily report for " . $r["report_date"]);
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
    return (count($clean) === 1 ? "Activity saved." : count($clean) . " activities saved.")
        . ($waiting && $r["reviewer_name"] ? " " . $r["reviewer_name"] . " can review the done ones now." : "");
}

/** Closing the day: the author sends their report. Returns [report, message]. */
function drClose(PDO $pdo, array $p, $id)
{
    $r = drOwn($pdo, $p, $id);
    if ($r["status"] !== "planned") {
        throw new PortalWriteError("It's already closed.");
    }
    if (!drCanFill($p, $r, drToday($pdo))) {
        throw new PortalWriteError("This day has passed, so the report can't change.");
    }
    $stmt = $pdo->prepare("UPDATE daily_reports SET status = 'submitted', started = 1, submitted_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'planned'");
    $stmt->execute([$r["id"]]);
    if (!$stmt->rowCount()) {
        throw new PortalWriteError("It's already closed.", 409);
    }
    logEvent($pdo, EVENT_REPORT_CLOSED, "daily_report", $r["id"], $r["admin_name"], "Sent the daily report for " . $r["report_date"]);
    return [$r, "Report closed and sent to " . ($r["reviewer_name"] ?: "your reviewer") . ". They've been told it's ready."];
}

/**
 * Files on the author's report: the ones kept plus new uploads, while it's
 * open or while fixing sent-back work. Returns the paths to unlink after.
 */
function drUpdateFiles(PDO $pdo, array $p, $id, $keepIds, array $files)
{
    $r = drOwn($pdo, $p, $id);
    $today = drToday($pdo);
    if (!drCanFill($p, $r, $today) && !drCanFix($p, $r, $today)) {
        throw new PortalWriteError($r["status"] === "reviewed" ? "It has been reviewed, so it can't change." : "This report is locked.");
    }
    $pdo->beginTransaction();
    try {
        $gone = saveAttachments($pdo, DAILY_REPORT_FILES_OWNER, $r["id"], is_array($keepIds) ? $keepIds : [], $files, (int) $p["id"]);
        $pdo->prepare("UPDATE daily_reports SET started = 1, updated_at = NOW() WHERE id = ?")->execute([$r["id"]]);
        if ($r["status"] !== "planned") {
            logEvent($pdo, EVENT_REPORT_EDITED, "daily_report", $r["id"], $r["admin_name"], "Changed the files on the daily report for " . $r["report_date"]);
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

    $message = $state === "changes"
        ? "Sent back to " . $r["admin_name"] . " with your feedback."
        : "Marked as reviewed." . ($feedback !== "" ? " " . $r["admin_name"] . " sees your feedback." : "");
    return [$r, $a + ["new_state" => $state], $message];
}

/**
 * The whole report reviewed, once closed: remaining Done activities are
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
            "Reviewed " . $r["admin_name"] . "'s daily report for " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $n = count($bulk);
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
    if (!drCanDelete($p, $r, drToday($pdo))) {
        throw new PortalWriteError($r["report_date"] < drToday($pdo) ? "Its day has passed, so it stays on record." : "Work has started on it, so it can't be deleted.");
    }

    $pdo->beginTransaction();
    try {
        $paths = deleteAttachmentRows($pdo, DAILY_REPORT_FILES_OWNER, $r["id"]);
        $pdo->prepare("DELETE h FROM daily_report_activity_reviews h JOIN daily_report_activities x ON x.id = h.activity_id WHERE x.report_id = ?")->execute([$r["id"]]);
        $pdo->prepare("DELETE FROM daily_report_activities WHERE report_id = ?")->execute([$r["id"]]);
        $pdo->prepare("DELETE FROM daily_reports WHERE id = ?")->execute([$r["id"]]);
        logEvent($pdo, EVENT_REPORT_DELETED, "daily_report", $r["id"], $r["admin_name"],
            "Deleted the daily report for " . $r["admin_name"] . " on " . $r["report_date"]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $mine = $r["admin_id"] === (int) $p["id"];
    return [$r, $mine ? "Your report for today is deleted." : $r["admin_name"] . "'s report is deleted. They've been told.", $paths];
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
    $reviewer = (int) ($in["reviewer_id"] ?? 0) ?: null;
    if ($reviewer) {
        drCheckReviewer($pdo, $reviewer, []);
    }
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
