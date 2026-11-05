<?php

/*
| AI activity - every call an AI assistant made into the portal, for whoever
| holds events.view. The AI activity tab on Tracking, through the same helpers
| in api/events.php.
|
|   GET ai-activity.php?person_kind=&person_id=&app=&result=&from=&to=&cursor=&limit=
|       get_ai_activity
|
| Scope events:read, checked against events.view again here so a token can never
| outlive a change of permission. Newest first, cursor-paged. Says who, through
| which app, which tool and how it went - never what was sent or returned.
|
| Names were typed by people (an app names itself when it registers), so they
| come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../events.php";

$p = v1Principal($pdo);
v1RequireStaff($p);
v1RequireMethod("GET");
v1RequireScope($p, "events:read");

if (!can($p, "events.view", $pdo)) {
    v1Error(403, "insufficient_scope", "You do not have permission to see Tracking.");
}

$limit = v1Limit();
$after = v1IdCursor();

$kind = v1EnumParam("person_kind", ["staff", "client"]);

[$where, $params] = aiActivityFilters([
    "person_kind" => ["staff" => "admin", "client" => "user"][$kind] ?? "",
    "person_id"   => v1IntParam("person_id"),
    "app"         => v1TextParam("app"),
    "result"      => v1EnumParam("result", ["ok", "refused"]),
    "from"        => v1DateParam("from"),
    "to"          => v1DateParam("to"),
]);

if ($after !== null) {
    $where[] = "l.id < ?";
    $params[] = $after;
}

$stmt = $pdo->prepare("
    SELECT " . AI_ACTIVITY_COLUMNS . AI_ACTIVITY_FROM . "
    " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
    ORDER BY l.id DESC
    LIMIT " . ($limit + 1)
);
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, function ($r) {
    $row = aiActivityRow($r);
    return [
        "id" => $row["id"],
        "person" => ["kind" => $row["person_kind"], "id" => $row["person_id"]],
        "app_id" => $row["app_id"],
        "tool" => $row["tool"],
        "endpoint" => $row["endpoint"],
        "method" => $row["method"],
        "status" => $row["status"],
        "result" => $row["status"] < 400 ? "ok" : "refused",
        "created_at" => v1Time($row["created_ts"]),
        "untrusted_content" => [
            "person_name" => $row["person"],
            "app_name" => $row["app"],
        ],
    ];
}));
