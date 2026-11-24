<?php

/*
|--------------------------------------------------------------------------
| Activity reports - reading (the pages' endpoint)
|--------------------------------------------------------------------------
|
| GET ?action=
|   pending_count   my reports that need me: every one not sent yet, plus
|                   any with work sent back (the sidebar badge)
|   summary         the Dashboard's cards (drSummaryFor); department=
|   mine            my reports, newest first, and open_reports: every one
|                   not sent yet, any day
|   get&id=         one report I may see, with the can_* flags
|   plan_options    who I can plan for, reviewers, departments, templates
|                   (reports.create or reports.self)
|   templates       the templates (reports.create)
|   template&id=    one template to edit (its maker, or the Director)
|   filters         Team reports' pickers: departments and people
|   list            Team reports: department, admin_id, from, to, status;
|                   one page at a time (limit, cursor -> next_cursor);
|                   stats.activities counts the filtered reports' activities
|                   by status, and stats.reports how many reports those are
|
| Every reply carries `today`: the portal's day, which the pages use instead
| of the browser's. The rules are in activity-report-lib.php; writes are
| activity-report-writes.php.
|
*/

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

require_once "db.php";
require_once "auth.php";
requirePage($pdo, "daily_reports"); // "Open X" in Settings > Permissions
require_once "activity-report-lib.php";

function reply($success, $message = "", $extra = [], $code = 200)
{
    global $pdo;
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message, "today" => drToday($pdo)], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// DDL before anything else.
ensureDailyReportTables($pdo);

$p = currentPrincipal($pdo);
// A browser session only: an AI connection comes in through api/v1.
if (!$p || $p["kind"] !== "admin" || $p["via"] !== "session") {
    reply(false, "Unauthorized token", [], 401);
}
$p["id"] = (int) $p["id"];

$action = (string) ($_GET["action"] ?? "");
$today = drToday($pdo);

$isDate = function ($value) {
    $d = DateTime::createFromFormat("Y-m-d", (string) $value);
    return $d && $d->format("Y-m-d") === $value;
};

try {
    if ($action === "pending_count") {
        reply(true, "", ["pending" => drPendingCount($pdo, $p)]);
    }

    // The Dashboard's Activity reports cards: my first report today and how many
    // of mine are open, what waits for my review, and - for whoever sees the
    // team's reports - today's and every older open one counted by status
    // (?department= an id, or "none").
    if ($action === "summary") {
        $dept = (string) ($_GET["department"] ?? "");
        reply(true, "", ["summary" => drSummaryFor($pdo, $p, $dept === "none" ? "none" : ((int) $dept ?: null))]);
    }

    if ($action === "mine") {
        $mine = drFetch($pdo, "r.admin_id = ?", [$p["id"]], false, "ORDER BY r.report_date DESC, r.id DESC LIMIT 120");
        // today_sheet: the first one made today, for anything still reading it.
        $todayOne = null;
        $items = [];
        $upcoming = [];
        foreach ($mine as $r) {
            $s = drSummary($pdo, $p, $r);
            if ($r["report_date"] === $today) {
                $todayOne = $s;
            }
            if ($r["report_date"] > $today) {
                $upcoming[] = $s;
            } else {
                $items[] = $s;
            }
        }
        // Every open one, however far back: the list above stops at 120.
        $open = array_values(array_map(function ($r) use ($pdo, $p) { return drSummary($pdo, $p, $r); },
            drFetch($pdo, "r.admin_id = ? AND r.status = 'planned'", [$p["id"]])));
        reply(true, "", ["today_sheet" => $todayOne, "open_reports" => $open, "upcoming" => $upcoming, "items" => $items,
            "can_self" => can($p, "reports.self", $pdo)]);
    }

    if ($action === "get") {
        $r = drFind($pdo, (int) ($_GET["id"] ?? 0));
        if (!$r || !drCanSee($pdo, $p, $r)) {
            reply(false, "This report doesn't exist, or it isn't one you can see.", [], 404);
        }
        reply(true, "", array_merge(["report" => drFull($pdo, $p, $r)], drFlags($pdo, $p, $r)));
    }

    if ($action === "plan_options") {
        if (!can($p, "reports.create", $pdo) && !can($p, "reports.self", $pdo)) {
            reply(false, "You can't plan reports.", [], 403);
        }
        $stmt = $pdo->prepare("
            SELECT a.id, a.name, a.position, a.department_id, d.name AS department_name, a.managed_by_admin_id
            FROM admins a LEFT JOIN departments d ON d.id = a.department_id WHERE a.id = ?
        ");
        $stmt->execute([$p["id"]]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC);
        $departments = $pdo->query("SELECT id, name, active FROM departments WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        reply(true, "", [
            "me" => ["id" => $p["id"], "name" => $me["name"], "position" => $me["position"],
                "department_id" => $me["department_id"] !== null ? (int) $me["department_id"] : null,
                "department_name" => $me["department_name"],
                "manager_id" => $me["managed_by_admin_id"] !== null ? (int) $me["managed_by_admin_id"] : null],
            "people" => drPlannable($pdo, $p),
            "reviewers" => drReviewers($pdo),
            "departments" => array_map(function ($d) {
                return ["id" => (int) $d["id"], "name" => $d["name"], "active" => (int) $d["active"]];
            }, $departments),
            "templates" => can($p, "reports.create", $pdo) ? drTemplates($pdo, $p) : [],
        ]);
    }

    if ($action === "templates") {
        if (!can($p, "reports.create", $pdo)) {
            reply(false, "You can't manage templates.", [], 403);
        }
        reply(true, "", ["templates" => drTemplates($pdo, $p)]);
    }

    if ($action === "template") {
        if (!can($p, "reports.create", $pdo)) {
            reply(false, "You can't manage templates.", [], 403);
        }
        $t = drTemplates($pdo, $p, (int) ($_GET["id"] ?? 0))[0] ?? null;
        if (!$t) {
            reply(false, "That template doesn't exist.", [], 404);
        }
        if (!$t["can_edit"]) {
            reply(false, "Only " . $t["created_by_name"] . " or the Director can change this template.", [], 403);
        }
        reply(true, "", ["template" => $t]);
    }

    // The rest is Team reports: whoever reviews, plans or sees every report.
    if (!(can($p, "reports.review", $pdo) || drSeesAll($pdo, $p) || can($p, "reports.create", $pdo))) {
        reply(false, "You can't see the team's reports.", [], 403);
    }
    [$scope, $scopeParams] = drTeamScopeSql($pdo, $p);

    if ($action === "filters") {
        $stmt = $pdo->prepare("SELECT DISTINCT r.admin_id FROM daily_reports r WHERE " . $scope);
        $stmt->execute($scopeParams);
        $ids = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_merge($ids, array_column(drPlannable($pdo, $p), "id"));
        if (can($p, "reports.review", $pdo)) {
            $ids = array_merge($ids, drPool($pdo, $p));
        }
        $ids = array_values(array_unique(array_filter($ids, function ($id) use ($p) { return $id !== $p["id"]; })));
        $people = [];
        if ($ids) {
            $people = $pdo->query("SELECT id, name, role FROM admins WHERE id IN (" . implode(",", array_map("intval", $ids)) . ") ORDER BY name")
                ->fetchAll(PDO::FETCH_ASSOC);
        }
        $departments = $pdo->query("SELECT id, name, active FROM departments ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
        reply(true, "", [
            "departments" => array_map(function ($d) {
                return ["id" => (int) $d["id"], "name" => $d["name"], "active" => (int) $d["active"]];
            }, $departments),
            "people" => array_map(function ($x) {
                return ["id" => (int) $x["id"], "name" => $x["name"], "role" => $x["role"]];
            }, $people),
        ]);
    }

    if ($action === "list") {
        $from = (string) ($_GET["from"] ?? "") ?: $today;
        $to = (string) ($_GET["to"] ?? "") ?: $from;
        if (!$isDate($from) || !$isDate($to)) {
            reply(false, "Dates must look like 2026-10-05.", [], 400);
        }
        $where = $scope . " AND r.report_date BETWEEN ? AND ?";
        $params = array_merge($scopeParams, [$from, $to]);

        $dept = (string) ($_GET["department"] ?? "");
        if ($dept === "none") {
            $where .= " AND r.department_id IS NULL";
        } elseif ($dept !== "") {
            $where .= " AND r.department_id = ?";
            $params[] = (int) $dept;
        }
        if ((int) ($_GET["admin_id"] ?? 0)) {
            $where .= " AND r.admin_id = ?";
            $params[] = (int) $_GET["admin_id"];
        }

        // Status is worked out from the activities, so it filters here rather
        // than in SQL; the date range keeps the set small.
        $inRange = drFetch($pdo, $where, $params);
        $status = (string) ($_GET["status"] ?? "");
        $rows = [];
        $shown = [];
        $waitingHere = 0;
        $stats = ["reports" => 0, "sent" => 0, "awaiting" => 0, "changes" => 0];
        foreach ($inRange as $r) {
            $s = drSummary($pdo, $p, $r);
            $stats["sent"] += $r["status"] !== "planned" ? 1 : 0;
            $stats["awaiting"] += $s["effective_status"] === "awaiting" ? 1 : 0;
            $stats["changes"] += $s["effective_status"] === "changes" ? 1 : 0;
            if ($status === "" || $s["effective_status"] === $status) {
                $rows[] = $s;
                $shown[] = $r;
                $waitingHere += drWaitsFor($r, $p["id"]) ? 1 : 0;
            }
        }
        // The donut follows every filter, status included.
        $stats["reports"] = count($shown);
        $stats["activities"] = drActivityCounts($shown);
        usort($rows, function ($a, $b) {
            return strcmp($b["report_date"], $a["report_date"])
                ?: (DR_STATUS_RANK[$a["effective_status"]] <=> DR_STATUS_RANK[$b["effective_status"]])
                ?: strcmp($a["admin_name"], $b["admin_name"]);
        });

        [$waitingCount, $waitingSince] = drWaitingForMeRange($pdo, $p);
        $limit = max(1, min(DR_LIST_PAGE_MAX, (int) ($_GET["limit"] ?? 50)));
        $start = max(0, (int) ($_GET["cursor"] ?? 0));
        $page = array_slice($rows, $start, $limit);

        reply(true, "", [
            "items" => $page,
            "next_cursor" => $start + $limit < count($rows) ? (string) ($start + $limit) : null,
            "total" => count($rows),
            "stats" => $stats,
            "count_totals" => drCounts($inRange),
            // The tab's count is every report waiting on you, whatever the
            // filters; waiting_here is how many of them these filters show,
            // so the page can offer the rest (from waiting_since) when it's fewer.
            "waiting_for_me" => $waitingCount,
            "waiting_since" => $waitingSince,
            "waiting_here" => $waitingHere,
        ]);
    }

    reply(false, "Unknown action.", [], 400);
} catch (PortalWriteError $e) {
    reply(false, $e->getMessage(), [], $e->status);
} catch (PDOException $e) {
    error_log("activity-reports.php: " . $e->getMessage());
    reply(false, "Activity reports aren't set up yet, or the database refused the request.", [], 500);
}
