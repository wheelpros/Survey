<?php

/*
| Content tenant guard (audit W-01) - required by run.php after writes.php,
| whose one() it uses.
|
| A post reaches a client's feed through content.client, a company name.
| The browser endpoint must resolve that name the way api/v1 resolves a
| client id (v1VisibleClient): to an account the caller can see. And a
| teammate who may not publish must not move a post to another feed.
|
| Fixtures: Amy (account manager) sees Acme Ltd (10) only; Sean (seo admin)
| sees Acme Ltd and is a teammate on post 9850, which Sam wrote for Acme.
| Bob (11) is Beta Co, Andy's client.
*/

seed($pdo, "content", [
    ["id" => 9850, "title" => "Tenant reel", "client" => "Acme Ltd", "content_type" => "social_media", "type_label" => "Social Media",
     "status" => "scheduled", "post_date" => day("+4"), "post_time" => "12:00:00", "created_by" => 2, "caption" => "<p>Acme only</p>"],
]);
seed($pdo, "content_assignees", [["content_id" => 9850, "admin_id" => 5]]);

/** Bob's notifications, and the titles of the posts in Beta Co's feed. */
function betaCoState(PDO $pdo)
{
    $notes = (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE recipient_kind = 'user' AND recipient_id = 11")["n"];
    $titles = $pdo->query("SELECT title FROM content WHERE client = 'Beta Co' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return [$notes, $titles];
}

function contentSave($token, array $fields)
{
    return post("api/admin-content.php", $token, $fields + ["content_type" => "articles", "type_label" => "Articles"], [], true);
}

$betaBefore = betaCoState($pdo);

// ── Creating for a client you can't see ──────────────────────────────────

[$status, $r] = contentSave($am, ["title" => "Tenant draft", "client" => "Beta Co", "status" => "draft"]);
check("content tenant: Amy can't draft a post for Beta Co, which isn't hers", $status === 403 && ($r["success"] ?? null) === false
    && !one($pdo, "SELECT id FROM content WHERE title = 'Tenant draft'"), "HTTP $status " . json_encode($r));

[$status, $r] = contentSave($am, ["title" => "Tenant intrusion", "client" => "Beta Co", "status" => "published", "publish_now" => "1"]);
check("content tenant: nor publish one into Beta Co's feed", $status === 403 && ($r["success"] ?? null) === false
    && !one($pdo, "SELECT id FROM content WHERE title = 'Tenant intrusion'"), "HTTP $status " . json_encode($r));

[$status, $r] = contentSave($am, ["title" => "Tenant ghost", "client" => "Nobody Inc", "status" => "draft"]);
check("content tenant: a company that is no client of hers is refused too", $status === 403 && ($r["success"] ?? null) === false
    && !one($pdo, "SELECT id FROM content WHERE title = 'Tenant ghost'"), "HTTP $status " . json_encode($r));

[$status, $r] = contentSave($am, ["title" => "Tenant own", "client" => "Acme Ltd", "status" => "draft"]);
$ownPost = one($pdo, "SELECT id, client FROM content WHERE title = 'Tenant own'");
check("content tenant: Amy still drafts for her own client", $status === 200 && $ownPost && $ownPost["client"] === "Acme Ltd",
    "HTTP $status " . json_encode($r));

[$status, $r] = contentSave($am, ["title" => "Tenant unfiled", "client" => "", "status" => "draft"]);
check("content tenant: a draft may still wait for its client", $status === 200 && one($pdo, "SELECT id FROM content WHERE title = 'Tenant unfiled'"),
    "HTTP $status " . json_encode($r));

[$status, $r] = contentSave($owner, ["title" => "Tenant owner", "client" => "Beta Co", "status" => "draft"]);
check("content tenant: the Director, who sees every client, drafts for Beta Co", $status === 200
    && one($pdo, "SELECT id FROM content WHERE title = 'Tenant owner'"), "HTTP $status " . json_encode($r));
$pdo->exec("DELETE FROM content WHERE title = 'Tenant owner'");

// ── Moving a post to another feed ────────────────────────────────────────

[$status, $r] = contentSave($seo, ["id" => 9850, "title" => "Tenant reel", "client" => "Beta Co", "caption" => "<p>Moved</p>"]);
$row = one($pdo, "SELECT client, caption FROM content WHERE id = 9850");
check("content tenant: Sean, a teammate who can't publish, can't move post 9850 to Beta Co", $status === 403
    && $row["client"] === "Acme Ltd" && $row["caption"] === "<p>Acme only</p>", "HTTP $status " . json_encode([$r, $row]));

[$status, $r] = contentSave($seo, ["id" => 9850, "title" => "Tenant reel", "client" => "Acme Ltd", "caption" => "<p>Filled in by Sean</p>"]);
$row = one($pdo, "SELECT client, caption FROM content WHERE id = 9850");
check("content tenant: but still fills it in for Acme", $status === 200 && $row["client"] === "Acme Ltd"
    && $row["caption"] === "<p>Filled in by Sean</p>", "HTTP $status " . json_encode([$r, $row]));

[$status, $r] = contentSave($am, ["id" => (int) $ownPost["id"], "title" => "Tenant own", "client" => "Beta Co", "status" => "draft"]);
check("content tenant: Amy, who may publish, can't move her own post to a client she can't see", $status === 403
    && one($pdo, "SELECT client FROM content WHERE id = ?", [(int) $ownPost["id"]])["client"] === "Acme Ltd", "HTTP $status " . json_encode($r));

check("content tenant: Beta Co's feed and Bob's notifications are untouched", betaCoState($pdo) === $betaBefore,
    json_encode([$betaBefore, betaCoState($pdo)]));

// ── The same rule on the AI path (parity, M-01) ──────────────────────────

refusedChange("content tenant: v1 refuses Amy a draft for Bob too", $am, "create_content_draft",
    ["client" => 11, "type" => "articles", "title" => "Tenant v1"], 404);

$pdo->exec("DELETE FROM content WHERE title IN ('Tenant own', 'Tenant unfiled')");
