<?php

/*
| GET me/content.php?from=&to=&cursor=&limit=   my_content
| GET me/content.php?id=N                       one post
|
| Posts that are live for the client: their company's, plus posts for every
| client (user-content.php). Drafts and scheduled posts don't exist here
| until they go live. Newest first; with from/to, forward in time.
*/

require_once __DIR__ . "/_me.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureContentColumns($pdo);

$company = trim((string) (meRow($pdo, $p)["company_name"] ?? ""));
$liveAt = contentLiveAtSql("c");
$visible = [meContentSql(), [contentNow(), $company, $company]];
$select = "SELECT c.*, $liveAt AS live_at FROM content c";

$id = v1IntParam("id");
if ($id !== null) {
    [$where, $params] = v1Where([$visible, ["c.id = ?", [$id]]]);
    $stmt = $pdo->prepare("$select $where LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        v1Error(404, "not_found", "No post you can see with that id.");
    }
    v1Reply(200, meContentItem($row, null));
}

$limit = v1Limit();
$from = v1DateParam("from");
$to = v1DateParam("to");
if ($from !== null && $to !== null && $to < $from) {
    v1Error(422, "invalid_parameter", "to must be on or after from.");
}
$direction = ($from !== null || $to !== null) ? "ASC" : "DESC";
$after = v1Cursor(2);

$parts = [$visible];
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
$stmt = $pdo->prepare("$select $where ORDER BY live_at $direction, c.id $direction LIMIT " . ($limit + 1));
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(string) $r["live_at"], (int) $r["id"]];
}, function ($r) {
    return meContentItem($r, 280);
}));
