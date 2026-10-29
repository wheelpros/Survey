<?php

/*
|--------------------------------------------------------------------------
| The Director's event log: reading it
|--------------------------------------------------------------------------
|
| Backs event-log.html. The Director's alone - every other role, and any AI
| token, gets a 403 (the AI reads the same log through api/v1/events.php,
| behind its own scope).
|
|   GET ?action=list&page=&limit=&actor_id=&area=&client_id=&from=&to=&q=
|       { items, total, page, total_pages }, newest first
|   GET ?action=filters
|       { admins, clients, areas } for the filter dropdowns
|
| Writing it is logEvent() in api/events.php.
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
    reply(false, "Only the Director can see the event log.", [], 403);
}

$action = $_GET["action"] ?? "list";

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
        WHERE approved = 1
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

[$where, $params] = adminEventFilters([
    "actor_id"  => (int) ($_GET["actor_id"] ?? 0),
    "area"      => (string) ($_GET["area"] ?? ""),
    "client_id" => (int) ($_GET["client_id"] ?? 0),
    "from"      => $date("from"),
    "to"        => $date("to"),
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
