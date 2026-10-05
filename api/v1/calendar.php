<?php

/*
| Calendar - meetings with clients, and the content dated in the same days.
|
|   GET calendar.php?from=&to=&client=   get_calendar
|   GET calendar.php?pending=1           list_pending_approvals
|   GET calendar.php?id=N                get_meeting
|
| Scope calendar:read, held by the owner, super admins and account managers -
| calendar.php closes itself to seo_admin. Meetings follow calendar.php's
| scopedClients(): the owner sees every client's, everyone else those of the
| clients assigned to them. The content in the range follows content's own
| rule and only appears with content:read as well.
|
| Meeting topics and notes are typed by clients as often as by staff, so they
| come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "calendar:read");

ensureUserProfileColumns($pdo);
ensureAppointmentTables($pdo);

// The widest range one call may ask for, and the most rows it returns.
const CALENDAR_MAX_DAYS = 366;
const CALENDAR_MAX_ROWS = 500;

$scope = v1ClientScopeSql($p, "a.user_id");

const MEETING_SQL = "
    SELECT a.id, a.user_id, a.title, a.topic, a.notes, a.date, a.time, a.status,
           a.requested_by, a.created_at,
           u.name AS user_name, u.email AS user_email, u.company_name,
           ad.name AS admin_name
    FROM appointments a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN admins ad ON ad.id = a.admin_id
";

function meetingItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "client" => v1ClientRef($r["user_id"], $r["user_name"], $r["company_name"], $r["user_email"]),
        "date" => (string) $r["date"],
        "time" => substr((string) $r["time"], 0, 5),
        // pending | approved | rejected
        "status" => (string) $r["status"],
        // 'admin': W|ZONE asked the client, who answers it.
        // 'user':  the client asked, and an admin answers it.
        "requested_by" => (string) ($r["requested_by"] ?? "admin"),
        // Who asked, or who answered the client's request.
        "admin_name" => v1NullableString($r["admin_name"]),
        "created_at" => (string) $r["created_at"],
        "untrusted_content" => [
            "topic" => (string) ($r["topic"] ?? $r["title"] ?? ""),
            "notes" => (string) ($r["notes"] ?? ""),
        ],
    ];
}

function meetings(PDO $pdo, array $parts, $order, $max)
{
    [$where, $params] = v1Where($parts);
    $stmt = $pdo->prepare(MEETING_SQL . $where . " ORDER BY $order LIMIT " . ($max + 1));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return [array_map("meetingItem", array_slice($rows, 0, $max)), count($rows) > $max];
}

$id = v1IntParam("id");

if ($id !== null) {
    [$found] = meetings($pdo, [["a.id = ?", [$id]], $scope], "a.id", 1);
    if (!$found) {
        v1Error(404, "not_found", "No meeting with that id among your clients' meetings.");
    }
    v1Reply(200, $found[0]);
}

if (!empty($_GET["pending"])) {
    $order = "a.date ASC, a.time ASC, a.id ASC";
    // A client asked; one of the admin team answers (calendar.php respond_request).
    [$onYou, $moreOnYou] = meetings($pdo, [
        $scope, ["a.requested_by = 'user' AND a.status = 'pending'", []],
    ], $order, 200);
    // W|ZONE asked; the client hasn't answered yet.
    [$onClient, $moreOnClient] = meetings($pdo, [
        $scope, ["a.requested_by = 'admin' AND a.status = 'pending'", []],
    ], $order, 200);

    v1Reply(200, [
        "waiting_on_you" => $onYou,
        "waiting_on_client" => $onClient,
        "truncated" => $moreOnYou || $moreOnClient,
    ]);
}

$from = v1DateParam("from", date("Y-m-d"));
$to = v1DateParam("to", date("Y-m-d", strtotime($from . " +30 days")));
if ($to < $from) {
    v1Error(422, "invalid_parameter", "to must be on or after from.");
}
if ((strtotime($to) - strtotime($from)) / 86400 > CALENDAR_MAX_DAYS) {
    v1Error(422, "invalid_parameter", "Ask for at most " . CALENDAR_MAX_DAYS . " days at a time.");
}

$parts = [$scope, ["a.date BETWEEN ? AND ?", [$from, $to]]];
$company = null;

$client = v1IntParam("client");
if ($client !== null) {
    $company = trim((string) (v1VisibleClient($pdo, $p, $client)["company_name"] ?? ""));
    $parts[] = ["a.user_id = ?", [$client]];
}

[$meetingItems, $truncated] = meetings($pdo, $parts, "a.date ASC, a.time ASC, a.id ASC", CALENDAR_MAX_ROWS);

$data = ["from" => $from, "to" => $to, "meetings" => $meetingItems];

if (hasScope($p, "content:read")) {
    ensureContentColumns($pdo);
    $now = contentNow();
    $liveAt = contentLiveAtSql("c");

    $contentParts = [
        v1SeesAllContent($p) ? ["", []] : ["c.created_by = ?", [$p["id"]]],
        ["$liveAt BETWEEN ? AND ?", [$from . " 00:00:00", $to . " 23:59:59"]],
    ];
    if ($client !== null) {
        // Tied by company name; a client without one has no content.
        $contentParts[] = $company === "" ? ["1 = 0", []] : ["c.client = ?", [$company]];
    }
    [$where, $params] = v1Where($contentParts);

    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.client, c.type_label,
               " . contentStatusSql("c") . " AS status, $liveAt AS live_at
        FROM content c
        $where
        ORDER BY live_at ASC, c.id ASC
        LIMIT " . (CALENDAR_MAX_ROWS + 1)
    );
    $stmt->execute(array_merge([$now], $params));
    $rows = $stmt->fetchAll();

    $truncated = $truncated || count($rows) > CALENDAR_MAX_ROWS;
    $data["content"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "client" => v1NullableString($r["client"]),
            "type_label" => v1NullableString($r["type_label"]),
            "status" => (string) $r["status"],
            "live_at" => v1DateTime($r["live_at"]),
        ];
    }, array_slice($rows, 0, CALENDAR_MAX_ROWS));
}

// More than CALENDAR_MAX_ROWS of either: ask for a shorter range.
$data["truncated"] = $truncated;

v1Reply(200, $data);
