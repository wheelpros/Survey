<?php

/*
| Content - the posts admins write for clients.
|
|   GET content.php?client=&from=&to=&status=&cursor=&limit=   list_content
|   GET content.php?id=N                                      get_content
|   GET content.php?types=1                                   list_content_types
|
| Scope content:read. Same rule as admin-content.php: the owner and account
| managers see the whole library, everyone else only the posts they wrote.
|
| A scheduled post goes live by itself once its date and time pass - nothing
| moves it, the reads ask (db.php contentIsLiveSql). So `status` here is the
| status a client would see right now: a scheduled post whose moment has
| passed reads as published. `live_at` is when it went or goes live.
|
| Without from/to the list is newest first; with either, it runs forward in
| time from `from`, which is what a calendar wants.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "content:read");

if (!empty($_GET["types"])) {
    v1Reply(200, ["items" => array_map(function ($title) {
        return [
            "id" => trim(preg_replace("/[^a-z0-9]+/", "_", strtolower($title)), "_"),
            "label" => $title,
        ];
    }, CONTENT_TYPE_TITLES)]);
}

ensureContentColumns($pdo);

$now = contentNow();
$own = v1SeesAllContent($p) ? ["", []] : ["c.created_by = ?", [$p["id"]]];
$liveAt = contentLiveAtSql("c");

// Binds one ? (contentNow()) for the status column.
$select = "
    SELECT c.id, c.title, c.client, c.link, c.caption, c.content_type, c.type_label,
           c.platform, c.category, c.orientation, c.media_path, c.post_date, c.post_time,
           c.created_at, c.updated_at,
           " . contentStatusSql("c") . " AS status,
           $liveAt AS live_at,
           a.name AS created_by_name
    FROM content c
    LEFT JOIN admins a ON a.id = c.created_by
";

function contentItem(array $r, $captionMax)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        // The client's company name; null for a post for every client.
        "client" => v1NullableString($r["client"]),
        "type" => (string) $r["content_type"],
        "type_label" => v1NullableString($r["type_label"]),
        "platform" => v1NullableString($r["platform"]),
        // draft | scheduled | published, as clients see it right now.
        "status" => (string) $r["status"],
        "live_at" => v1DateTime($r["live_at"]),
        "has_media" => !empty($r["media_path"]),
        "link" => v1NullableString($r["link"] ?? null),
        "created_by_name" => v1NullableString($r["created_by_name"]),
        "untrusted_content" => ["caption" => v1PlainText($r["caption"] ?? "", $captionMax)],
    ];
}

$id = v1IntParam("id");

if ($id !== null) {
    [$where, $params] = v1Where([["c.id = ?", [$id]], $own]);
    $stmt = $pdo->prepare($select . $where . " LIMIT 1");
    $stmt->execute(array_merge([$now], $params));
    $row = $stmt->fetch();

    if (!$row) {
        v1Error(404, "not_found", "No content with that id among the posts you can see.");
    }

    $data = contentItem($row, null);
    $data["orientation"] = (string) $row["orientation"];
    $data["created_at"] = (string) $row["created_at"];
    $data["updated_at"] = (string) $row["updated_at"];
    v1Reply(200, $data);
}

$limit = v1Limit();
$from = v1DateParam("from");
$to = v1DateParam("to");
if ($from !== null && $to !== null && $to < $from) {
    v1Error(422, "invalid_parameter", "to must be on or after from.");
}
$direction = ($from !== null || $to !== null) ? "ASC" : "DESC";
$after = v1Cursor(2);

$parts = [$own];

$client = v1IntParam("client");
if ($client !== null) {
    $company = trim((string) (v1VisibleClient($pdo, $p, $client)["company_name"] ?? ""));
    if ($company === "") {
        // Content is tied to a client by company name; without one there is none.
        v1Reply(200, ["items" => [], "next_cursor" => null]);
    }
    $parts[] = ["c.client = ?", [$company]];
}

$status = v1EnumParam("status", ["draft", "scheduled", "published"]);
if ($status !== null) {
    $parts[] = ["(" . contentStatusSql("c") . ") = ?", [$now, $status]];
}
if ($from !== null) {
    $parts[] = ["$liveAt >= ?", [$from . " 00:00:00"]];
}
if ($to !== null) {
    $parts[] = ["$liveAt <= ?", [$to . " 23:59:59"]];
}
if ($after !== null) {
    $parts[] = v1KeysetSql($liveAt, "c.id", $after, $direction);
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare($select . $where . " ORDER BY live_at $direction, c.id $direction LIMIT " . ($limit + 1));
$stmt->execute(array_merge([$now], $params));

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(string) $r["live_at"], (int) $r["id"]];
}, function ($r) {
    return contentItem($r, 280);
}));
