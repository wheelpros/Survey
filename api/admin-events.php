<?php

/*
|--------------------------------------------------------------------------
| The event log: reading it
|--------------------------------------------------------------------------
|
| Backs event-log.html. Whoever holds events.view (the Director, and anyone
| they gave it to in Settings > Permissions) - everyone else, and any AI
| token, gets a 403 (the AI reads the same log through api/v1/events.php,
| behind its own scope).
|
|   GET ?action=list&page=&limit=&actor_id=&area=&client_id=&from=&to=&tz=&q=
|       (tz: the browser's IANA zone, so from / to are its own days)
|       { items, total, page, total_pages }, newest first
|   GET ?action=filters
|       { admins, clients, areas } for the filter dropdowns
|
| The page's AI activity tab, under the same permission (the assistant's route
| is api/v1/ai-activity.php):
|
|   GET ?action=ai_activity&page=&limit=&who=&app=&result=&from=&to=&tz=
|       (who: "admin:3" or "user:10"; result: ok or refused)
|       { items, total, page, total_pages }, newest first
|   GET ?action=ai_filters
|       { people, apps } for its filter dropdowns
|
| Writing the log is logEvent() in api/events.php.
|
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";
require_once "events.php";

function reply($success, $message = "", $extra = [], $status = 200)
{
    http_response_code($status);
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$p = currentPrincipal($pdo);

if (!$p) {
    reply(false, "Unauthorized token", [], 401);
}

if ($p["kind"] !== "admin" || $p["via"] !== "session" || !can($p, "events.view", $pdo)) {
    reply(false, "You do not have permission to see Tracking.", [], 403);
}

$action = $_GET["action"] ?? "list";

$date = function ($name) {
    $value = trim((string) ($_GET[$name] ?? ""));
    if ($value === "") {
        return "";
    }
    $d = DateTime::createFromFormat("Y-m-d", $value);
    if (!$d || $d->format("Y-m-d") !== $value) {
        reply(false, "Dates must look like 2026-10-05.", [], 400);
    }
    return $value;
};

if ($action === "ai_filters") {
    ensureOAuthTables($pdo);

    // Everyone who appears in the log, deleted people included: their rows stay.
    $people = $pdo->query("
        SELECT l.principal_kind AS kind, l.principal_id AS id, MAX(COALESCE(a.name, u.name)) AS name
        " . AI_ACTIVITY_FROM . "
        GROUP BY l.principal_kind, l.principal_id
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $apps = $pdo->query("
        SELECT l.client_id AS id, MAX(c.client_name) AS name
        " . AI_ACTIVITY_FROM . "
        GROUP BY l.client_id
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    reply(true, "", [
        "people" => array_map(function ($row) {
            return [
                "value" => $row["kind"] . ":" . (int) $row["id"],
                "name" => (string) ($row["name"] ?: "(deleted)"),
                "kind" => $row["kind"] === "user" ? "client" : "staff",
            ];
        }, $people),
        "apps" => array_map(function ($row) {
            return ["value" => (string) $row["id"], "name" => (string) ($row["name"] ?: "AI assistant")];
        }, $apps),
    ]);
}

if ($action === "ai_activity") {
    ensureOAuthTables($pdo);

    $who = explode(":", (string) ($_GET["who"] ?? ""), 2);
    $kind = in_array($who[0], ["admin", "user"], true) ? $who[0] : "";

    [$where, $params] = aiActivityFilters([
        "person_kind" => $kind,
        "person_id"   => $kind !== "" ? (int) ($who[1] ?? 0) : 0,
        "app"         => mb_substr((string) ($_GET["app"] ?? ""), 0, 64),
        "result"      => (string) ($_GET["result"] ?? ""),
        "from"        => $date("from"),
        "to"          => $date("to"),
        "tz"          => (string) ($_GET["tz"] ?? ""),
    ]);

    $sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

    $limit = max(1, min(100, (int) ($_GET["limit"] ?? 25)));
    $page = max(1, (int) ($_GET["page"] ?? 1));

    $count = $pdo->prepare("SELECT COUNT(*) FROM mcp_audit_log l $sqlWhere");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT " . AI_ACTIVITY_COLUMNS . AI_ACTIVITY_FROM . "
        $sqlWhere
        ORDER BY l.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    reply(true, "", [
        "items" => array_map("aiActivityRow", $stmt->fetchAll(PDO::FETCH_ASSOC)),
        "total" => $total,
        "page" => $page,
        "total_pages" => $totalPages,
    ]);
}

if ($action === "filters") {

    // Everyone who appears in the log, removed admins included - their rows
    // stay, so the filter has to be able to find them.
    $admins = $pdo->query("
        SELECT e.actor_admin_id AS id,
               COALESCE(a.name, MAX(e.actor_name)) AS name,
               COALESCE(a.role, MAX(e.actor_role)) AS role,
               a.id IS NULL AS removed
        FROM admin_events e
        LEFT JOIN admins a ON a.id = e.actor_admin_id
        GROUP BY e.actor_admin_id, a.id, a.name, a.role
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $clients = $pdo->query("
        SELECT id, name, company_name
        FROM users
        WHERE approved = 1 AND account_owner_id IS NULL
        ORDER BY COALESCE(NULLIF(company_name, ''), name)
    ")->fetchAll(PDO::FETCH_ASSOC);

    $areas = [];
    foreach (EVENT_AREAS as $key => $label) {
        $areas[] = ["key" => $key, "label" => $label];
    }

    reply(true, "", [
        "admins" => array_map(function ($a) {
            return ["id" => (int) $a["id"], "name" => (string) $a["name"], "role" => (string) $a["role"], "removed" => (bool) $a["removed"]];
        }, $admins),
        "clients" => array_map(function ($c) {
            return ["id" => (int) $c["id"], "name" => (string) ($c["company_name"] ?: $c["name"])];
        }, $clients),
        "areas" => $areas,
    ]);
}

if ($action !== "list") {
    reply(false, "Unknown action", [], 400);
}

pruneAdminEvents($pdo);

[$where, $params] = adminEventFilters([
    "actor_id"  => (int) ($_GET["actor_id"] ?? 0),
    "area"      => (string) ($_GET["area"] ?? ""),
    "client_id" => (int) ($_GET["client_id"] ?? 0),
    "from"      => $date("from"),
    "to"        => $date("to"),
    "tz"        => (string) ($_GET["tz"] ?? ""),
    "q"         => mb_substr(trim((string) ($_GET["q"] ?? "")), 0, 100),
]);

$sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

$limit = max(1, min(100, (int) ($_GET["limit"] ?? 25)));
$page = max(1, (int) ($_GET["page"] ?? 1));

$count = $pdo->prepare("SELECT COUNT(*) FROM admin_events $sqlWhere");
$count->execute($params);
$total = (int) $count->fetchColumn();

$totalPages = max(1, (int) ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts
    FROM admin_events
    $sqlWhere
    ORDER BY id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);

reply(true, "", [
    "items" => array_map("adminEventRow", $stmt->fetchAll(PDO::FETCH_ASSOC)),
    "total" => $total,
    "page" => $page,
    "total_pages" => $totalPages,
]);
