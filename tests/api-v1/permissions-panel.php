<?php

/*
| Settings > Permissions as the page uses it - required by run.php, sharing
| its scope. What the panel reads (each answer's source, what the caller may
| not change, which keys are risky), that its picture of each person is the
| one their own pages get, and that one Save is stored whole or not at all.
| Its own people, so nothing earlier changes them:
|
|   881 Rae   super_admin       handed permissions.manage
|   882 Tom   seo_admin
|   883 Uma   account_manager   her own Allow for clients.view_all
|   884 Vic   super_admin
*/

seed($pdo, "admins", [
    ["id" => 881, "name" => "Rae", "email" => "rae@w.test", "role" => "super_admin", "session_token" => "tok-rae"],
    ["id" => 882, "name" => "Tom", "email" => "tom@w.test", "role" => "seo_admin", "session_token" => "tok-tom"],
    ["id" => 883, "name" => "Uma", "email" => "uma@w.test", "role" => "account_manager", "session_token" => "tok-uma"],
    ["id" => 884, "name" => "Vic", "email" => "vic@w.test", "role" => "super_admin", "session_token" => "tok-vic"],
]);
seed($pdo, "admin_permission_overrides", [
    ["admin_id" => 881, "permission" => "permissions.manage", "allowed" => 1],
    ["admin_id" => 883, "permission" => "clients.view_all", "allowed" => 1],
]);

/** One person's row in a panel reply. */
function panelRow(array $reply, $id)
{
    foreach ($reply["data"]["admins"] ?? [] as $a) {
        if ((int) $a["id"] === $id) {
            return $a;
        }
    }
    return [];
}

function panelRole(array $reply, $key)
{
    foreach ($reply["data"]["roles"] ?? [] as $r) {
        if ($r["key"] === $key) {
            return $r;
        }
    }
    return [];
}

function catalogEntry(array $reply, $key)
{
    foreach ($reply["data"]["catalog"] ?? [] as $c) {
        if ($c["key"] === $key) {
            return $c;
        }
    }
    return [];
}

function eventCount(PDO $pdo)
{
    return (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events")["n"];
}

// ── What the panel reads ────────────────────────────────────────────────

[$status, $panel] = pageGet("api/permissions-admin.php", $owner);
check("panel: the Director reads it", $status === 200 && ($panel["data"]["me"]["director"] ?? null) === true, "HTTP $status");
check("panel: risky keys are marked",
    (catalogEntry($panel, "clients.view_all")["risky"] ?? null) === true
    && (catalogEntry($panel, "settings.manage")["risky"] ?? null) === true
    && (catalogEntry($panel, "pages.dashboard")["risky"] ?? null) === false);
check("panel: an area that works through one page says which",
    (catalogEntry($panel, "reports.self")["page"] ?? null) === "pages.daily_reports"
    && (catalogEntry($panel, "pages.daily_reports")["heads_area"] ?? null) === "reports"
    && (catalogEntry($panel, "team_forms.send")["page"] ?? null) === "pages.forms"
    && (catalogEntry($panel, "announcements.send")["page"] ?? null) === "pages.notifications"
    && (catalogEntry($panel, "team.overview")["page"] ?? null) === "pages.dashboard");
check("panel: every sidebar page heads an area, even with nothing under it",
    (catalogEntry($panel, "pages.files")["heads_area"] ?? null) === "files"
    && (catalogEntry($panel, "calendar.use")["heads_area"] ?? null) === "calendar"
    && (catalogEntry($panel, "clients.details")["heads_area"] ?? null) === "clients");
check("panel: Clients Management keys aren't greyed out (they still work through the AI), nor is seeing every client",
    array_key_exists("page", catalogEntry($panel, "clients.edit")) && catalogEntry($panel, "clients.edit")["page"] === null
    && catalogEntry($panel, "clients.view_all")["area"] === "everywhere" && catalogEntry($panel, "clients.view_all")["page"] === null);
check("panel: the areas run in sidebar order", array_values(array_unique(array_column($panel["data"]["catalog"] ?? [], "area")))
    === array_keys(PERMISSION_AREAS), json_encode(array_values(array_unique(array_column($panel["data"]["catalog"] ?? [], "area")))));
check("panel: the Director may change every role", array_filter(array_column($panel["data"]["roles"] ?? [], "locked")) === []);
check("panel: and every person here", panelRow($panel, 882)["locked"] === null && panelRow($panel, 881)["locked"] === null);

$uma = panelRow($panel, 883);
check("panel: an override says so", ($uma["sources"]["clients.view_all"] ?? "") === "override"
    && in_array("clients.view_all", $uma["permissions"] ?? [], true), json_encode($uma["sources"] ?? null));
$tom = panelRow($panel, 882);
check("panel: an inherited answer comes from the role", in_array($tom["sources"]["pages.dashboard"] ?? "", ["role", "default"], true)
    && !in_array("clients.view_all", $tom["permissions"] ?? [], true), json_encode($tom["sources"] ?? null));
check("panel: a source for every key", count($tom["sources"] ?? []) === count(PERMISSIONS));

// What the panel shows for each person is what their own pages get
// (admin-session.php, which feeds adminCan()).
foreach ([882 => "tok-tom", 883 => "tok-uma", 884 => "tok-vic", 881 => "tok-rae"] as $id => $token) {
    [, $session] = post("api/admin-session.php", $token, []);
    $theirs = $session["admin"]["permissions"] ?? null;
    $shown = panelRow($panel, $id)["permissions"] ?? [];
    if (is_array($theirs)) {
        sort($theirs);
    }
    sort($shown);
    check("panel: admin $id sees on their pages what the panel shows", $theirs === $shown,
        json_encode(["session" => $theirs, "panel" => $shown]));
}

// ── Someone handed permissions.manage ──────────────────────────────────

[$status, $delegate] = pageGet("api/permissions-admin.php", "tok-rae");
check("panel: a delegate reads it", $status === 200 && ($delegate["data"]["me"]["director"] ?? null) === false, "HTTP $status");
check("panel: their own role's column is locked, with the reason",
    is_string(panelRole($delegate, "super_admin")["locked"] ?? null) && panelRole($delegate, "seo_admin")["locked"] === null);
check("panel: their own row is locked", is_string(panelRow($delegate, 881)["locked"] ?? null)
    && panelRow($delegate, 882)["locked"] === null);

// ── One Save, all of it or none ────────────────────────────────────────

$before = eventCount($pdo);
[$status, $r] = post("api/permissions-admin.php", $owner, ["dry_run" => true, "batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => true]],
    ["role" => "seo_admin", "changes" => ["pages.files" => false]],
]]);
check("batch: a dry run says yes", $status === 200 && ($r["dry_run"] ?? false) === true, "HTTP $status " . json_encode($r));
check("batch: and stores nothing", !overrideRow($pdo, 882, "events.view")
    && !one($pdo, "SELECT 1 FROM role_permissions WHERE role = 'seo_admin' AND permission = 'pages.files'")
    && eventCount($pdo) === $before);

[$status, $r] = post("api/permissions-admin.php", $owner, ["batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => true]],
    ["role" => "owner", "changes" => ["pages.files" => false]],
]]);
check("batch: one refusal refuses the lot", $status === 422 && !overrideRow($pdo, 882, "events.view"), "HTTP $status " . json_encode($r));

[$status, $r] = post("api/permissions-admin.php", $owner, ["batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => true]],
    ["admin_id" => 882, "changes" => ["events.view" => false]],
]]);
check("batch: a person twice is refused", $status === 422 && !overrideRow($pdo, 882, "events.view"), "HTTP $status");

[$status, $r] = post("api/permissions-admin.php", "tok-rae", ["batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => true]],
    ["role" => "super_admin", "changes" => ["clients.view_all" => true]],
]]);
check("batch: a delegate's own role inside a batch is refused, and takes the rest with it",
    $status === 403 && !overrideRow($pdo, 882, "events.view")
    && !one($pdo, "SELECT 1 FROM role_permissions WHERE role = 'super_admin' AND permission = 'clients.view_all'"), "HTTP $status");

foreach (["nothing" => [], "not a list" => "everything", "a set with no target" => [["changes" => ["events.view" => true]]]] as $what => $batch) {
    [$status] = post("api/permissions-admin.php", $owner, ["batch" => $batch]);
    check("batch: refuses $what", $status === 422, "HTTP $status");
}

[$status, $saved] = post("api/permissions-admin.php", $owner, ["batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => true]],
    ["role" => "seo_admin", "changes" => ["pages.files" => false]],
]]);
check("batch: saves together", $status === 200 && (int) (overrideRow($pdo, 882, "events.view")["allowed"] ?? 0) === 1
    && (int) (one($pdo, "SELECT allowed FROM role_permissions WHERE role = 'seo_admin' AND permission = 'pages.files'")["allowed"] ?? 1) === 0,
    "HTTP $status " . json_encode($saved["message"] ?? null));
$tom = panelRow($saved, 882);
check("batch: the reply shows the new answers and their sources",
    ($tom["sources"]["events.view"] ?? "") === "override" && in_array("events.view", $tom["permissions"] ?? [], true)
    && ($tom["sources"]["pages.files"] ?? "") === "role" && !in_array("pages.files", $tom["permissions"] ?? [], true),
    json_encode($tom));

[, $again] = pageGet("api/permissions-admin.php", $owner);
check("batch: a reload shows exactly what the save replied", ($again["data"]["admins"] ?? null) === ($saved["data"]["admins"] ?? false)
    && ($again["data"]["matrix"] ?? null) === ($saved["data"]["matrix"] ?? false));
[, $session] = post("api/admin-session.php", "tok-tom", []);
check("batch: Tom's pages hear about both", in_array("events.view", $session["admin"]["permissions"] ?? [], true)
    && !in_array("pages.files", $session["admin"]["permissions"] ?? ["pages.files"], true), json_encode($session));
[$status] = pageGet("api/source-files.php", "tok-tom");
check("batch: and the Files endpoints refuse him", $status === 403, "HTTP $status");

// Back as it was.
[$status] = post("api/permissions-admin.php", $owner, ["batch" => [
    ["admin_id" => 882, "changes" => ["events.view" => "default"]],
    ["role" => "seo_admin", "changes" => ["pages.files" => "default"]],
]]);
check("batch: \"default\" puts both back", $status === 200 && !overrideRow($pdo, 882, "events.view")
    && !one($pdo, "SELECT 1 FROM role_permissions WHERE role = 'seo_admin' AND permission = 'pages.files'"), "HTTP $status");
forgetPermissions();

// ── Each page that heads an area really closes it ──────────────────────
// Settings greys an area out while its page is off; that is only true if
// the area's endpoint refuses then. One endpoint per PERMISSION_AREA_PAGES.

// Tom is given every key under the page first, so a refusal can only be the page.
$closes = [
    ["pages.dashboard", "api/admin-dashboard.php", ["team.overview"]],
    ["pages.forms", "api/admin-surveys.php", ["forms.review"]],
    ["pages.forms", "api/team-forms.php?action=pending_count", ["team_forms.send", "team_forms.responses"]],
    ["pages.content", "api/admin-content.php", ["content.create"]],
    ["pages.projects", "api/projects.php", ["projects.create"]],
    ["inquiries.read", "api/admin-inquiries.php", ["inquiries.write"]],
    ["pages.notifications", "api/announcements.php?action=recipients", ["announcements.send"]],
    ["pages.daily_reports", "api/activity-reports.php?action=pending_count", ["reports.self"]],
];
foreach ($closes as [$page, $endpoint, $keys]) {
    check("area pages: $page is one of PERMISSION_AREA_PAGES", in_array($page, PERMISSION_AREA_PAGES, true));
    $grant = array_fill_keys($keys, true);
    post("api/permissions-admin.php", $owner, ["admin_id" => 882, "changes" => $grant + [$page => true]]);
    [$open] = pageGet($endpoint, "tok-tom");
    post("api/permissions-admin.php", $owner, ["admin_id" => 882, "changes" => [$page => false]]);
    [$status] = pageGet($endpoint, "tok-tom");
    check("area pages: $endpoint opens with $page on and refuses with it off", $open === 200 && $status === 403, "HTTP $open then $status");
    post("api/permissions-admin.php", $owner, ["admin_id" => 882, "changes" => array_fill_keys(array_merge($keys, [$page]), "default")]);
}

// Every area Settings greys out is in the list above.
$greyed = array_unique(array_filter(array_column(permissionCatalog(), "page")));
$tested = array_column($closes, 0);
check("area pages: every page that greys its area out is tested", array_diff($greyed, $tested) === [], json_encode(array_values(array_diff($greyed, $tested))));
check("area pages: the AI connection closes with the same pages",
    scopePagePermission("team_forms:write") === "pages.forms" && scopePagePermission("announcements:write") === "pages.notifications"
    && scopePagePermission("inquiries:write") === "inquiries.read" && scopePagePermission("activity_reports:read") === "pages.daily_reports");
forgetPermissions();

// ── An action on a page that is off ────────────────────────────────────
// Dina (43, activity-reports.php) loses Activity Reports but keeps an Allow
// for planning her own: the page wins, and nobody can plan a report for her.

post("api/permissions-admin.php", $owner, ["admin_id" => 43, "changes" => ["pages.daily_reports" => false, "reports.self" => true]]);
[$status] = post("api/activity-report-writes.php", "tok-dr-dina", ["action" => "plan", "self" => true,
    "activities" => [["title" => "Inbox zero"]]]);
check("page off: her own Allow can't plan through a closed page", $status === 403, "HTTP $status");
[, $r] = pageGet("api/activity-reports.php?action=plan_options", $owner);
check("page off: she isn't offered to planners", ($r["success"] ?? false) === true
    && !in_array(43, array_map("intval", array_column($r["people"] ?? [], "id")), true), json_encode($r["people"] ?? null));
[$status] = post("api/activity-report-writes.php", $owner, ["action" => "plan", "admin_ids" => [43],
    "activities" => [["title" => "Inbox zero"]]]);
check("page off: nor can a report be planned for her", $status === 403, "HTTP $status");
post("api/permissions-admin.php", $owner, ["admin_id" => 43, "changes" => ["pages.daily_reports" => "default", "reports.self" => "default"]]);
forgetPermissions();
