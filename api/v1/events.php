<?php

/*
| The event log - what every admin changed, for whoever holds events.view.
|
|   GET events.php?actor_id=&area=&client_id=&from=&to=&q=&cursor=&limit=
|       list_admin_events
|
| Scope events:read, which needs events.view - the Director, and anyone they
| gave it to (SCOPE_PERMISSIONS in auth.php); it is checked again here so a
| token can never outlive a change of permission. The same rows and filters
| as event-log.html, through the same helpers in api/events.php - newest
| first, cursor-paged.
|
| Summaries and labels quote what people typed (a project title, a meeting
| topic, a rejection note), so they come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../events.php";

$p = v1Principal($pdo);
v1RequireStaff($p);
v1RequireMethod("GET");
v1RequireScope($p, "events:read");

if (!can($p, "events.view", $pdo)) {
    v1Error(403, "insufficient_scope", "You do not have permission to read the event log.");
}

pruneAdminEvents($pdo);

$limit = v1Limit();
$after = v1IdCursor();

[$where, $params] = adminEventFilters([
    "actor_id"  => v1IntParam("actor_id"),
    "area"      => v1EnumParam("area", array_keys(EVENT_AREAS)),
    "client_id" => v1IntParam("client_id"),
    "from"      => v1DateParam("from"),
    "to"        => v1DateParam("to"),
    "q"         => v1TextParam("q"),
]);

if ($after !== null) {
    $where[] = "id < ?";
    $params[] = $after;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM admin_events
    " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
    ORDER BY id DESC
    LIMIT " . ($limit + 1)
);
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, function ($r) {
    $row = adminEventRow($r);
    return [
        "id" => $row["id"],
        "action" => $row["action"],
        "area" => $row["area"],
        "area_label" => $row["area_label"],
        "actor" => [
            "id" => $row["actor_id"],
            "role" => $row["actor_role"],
        ],
        "via" => $row["via"],
        "target_type" => $row["target_type"],
        "target_id" => $row["target_id"],
        "client_id" => $row["client_id"],
        "created_at" => $row["created_at"],
        "untrusted_content" => [
            "summary" => $row["summary"],
            "actor_name" => $row["actor_name"],
            "target_label" => $row["target_label"],
        ],
    ];
}));
