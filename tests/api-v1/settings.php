<?php

/*
| Settings - required by run.php last, sharing its scope. Two halves, like
| writes.php:
|
|   1. The Settings page's endpoints, whose rules moved into
|      admin-settings-writes.php, still behave as they did.
|   2. get_settings and the settings:write / admins:write changes: the
|      owner's alone, through prepare -> confirm, held to the same rules.
|
| Two admins of its own (7, 8) take the role and on/off changes, so nothing
| the earlier files rely on moves.
*/

seed($pdo, "admins", [
    ["id" => 7, "name" => "Tess", "email" => "t@w.test", "role" => "seo_admin", "session_token" => "tok-tess"],
    ["id" => 8, "name" => "Max", "email" => "m@w.test", "role" => "super_admin", "session_token" => "tok-max"],
]);

function admin(PDO $pdo, $id)
{
    return one($pdo, "SELECT id, role, active, session_token, managed_by_admin_id, reset_token FROM admins WHERE id = ?", [$id]);
}

// ── Reading ──────────────────────────────────────────────────────────────

[$status, $s] = get("settings.php", $owner);
check("settings: the owner reads them", $status === 200 && count($s["data"]["admins"] ?? []) === 7
    && $s["data"]["login_slides"] === [] && $s["data"]["clients_may_use_ai"] === clientsMayUseMcp($pdo), "HTTP $status " . json_encode($s));
$flat = json_encode($s);
check("settings: no secrets", strpos($flat, "tok-") === false && strpos($flat, "password") === false
    && strpos($flat, "reset_token") === false && strpos($flat, "Olivia") === false, $flat);
check("settings: names are untrusted", ($s["data"]["admins"][0]["untrusted_content"]["name"] ?? null) === "Max"
    && !array_key_exists("name", $s["data"]["admins"][0]), $flat);
foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    refused("settings: a $who can't read them", "settings.php", $token, 403);
}
$fixtures["get_settings"] = $s["data"] ?? null;

foreach (["update_website_address" => ["address" => "x.test"], "change_admin_role" => ["admin" => 7, "role" => "super_admin"], "set_admin_position" => ["admin" => 7, "position" => "x"]] as $tool => $args) {
    refusedChange("settings: only the owner may $tool", $am, $tool, $args, 403);
}

// ── Website address ──────────────────────────────────────────────────────

refusedChange("website: not an address", $owner, "update_website_address", ["address" => "two words"], 422);
refusedChange("website: not a link that runs", $owner, "update_website_address", ["address" => "javascript://x%0Aalert(1)"], 422);
[$prep] = change("website: set", $owner, "update_website_address", ["address" => "wzone.test"]);
check("website: summary shows the link", strpos($prep["data"]["summary"] ?? "", "https://wzone.test") !== false, json_encode($prep));
check("website: saved as typed", getSiteSetting($pdo, "website_url") === "wzone.test");
change("website: cleared", $owner, "update_website_address", ["address" => ""]);
check("website: clearing it", getSiteSetting($pdo, "website_url", "none") === "none");
[, $r] = post("api/settings.php", $owner, ["action" => "save_website", "website_url" => "page.test"]);
check("website: the page still saves it", ($r["success"] ?? false) === true && getSiteSetting($pdo, "website_url") === "page.test", json_encode($r));
[, $r] = post("api/settings.php", $owner, ["action" => "save_website", "website_url" => "ftp://x"]);
check("website: the page still refuses a non-address", ($r["success"] ?? true) === false, json_encode($r));

// ── Logo and slides by link: checked on prepare, downloaded on confirm ───

refusedChange("logo: https only", $owner, "set_logo", ["image" => "http://example.com/l.png"], 422);
refusedChange("logo: no private links", $owner, "set_logo", ["image" => "https://10.0.0.5/l.png"], 422);
[$status, $prep] = prepare($owner, "set_logo", ["image" => "https://8.8.8.8/l.png"]);
check("logo: prepared", $status === 200 && strpos($prep["data"]["summary"] ?? "", "logo") !== false, "HTTP $status " . json_encode($prep));
refusedChange("slide: no localhost links", $owner, "add_login_slide", ["image" => "https://localhost/s.png"], 422);
[$status, $prep] = prepare($owner, "add_login_slide", ["image" => "https://8.8.8.8/s.png", "title" => "Hello"]);
check("slide: add prepared", $status === 200 && strpos($prep["data"]["summary"] ?? "", "\"Hello\"") !== false, "HTTP $status " . json_encode($prep));

// The rest of the slides need slides: added here as the page would.
ensureLoginSlidesTable($pdo);
seed($pdo, "login_slides", [
    ["id" => 1, "image_path" => "uploads/login-slides/a.png", "title" => "One", "position" => 0],
    ["id" => 2, "image_path" => "uploads/login-slides/b.png", "title" => "Two", "position" => 1],
    ["id" => 3, "image_path" => "uploads/login-slides/c.png", "title" => "Three", "position" => 2],
]);
$order = function () use ($pdo) {
    return array_map("intval", $pdo->query("SELECT id FROM login_slides ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
};

refusedChange("slide: nothing to change", $owner, "update_login_slide", ["slide" => 1], 422);
refusedChange("slide: not found", $owner, "update_login_slide", ["slide" => 99, "title" => "x"], 404);
change("slide: retitled", $owner, "update_login_slide", ["slide" => 1, "subtitle" => "Sub"]);
$one = one($pdo, "SELECT title, subtitle FROM login_slides WHERE id = 1");
check("slide: only the field given changes", $one["title"] === "One" && $one["subtitle"] === "Sub", json_encode($one));

refusedChange("slides: order must list every slide", $owner, "reorder_login_slides", ["order" => [2, 1]], 422);
refusedChange("slides: no repeats", $owner, "reorder_login_slides", ["order" => [1, 1, 2]], 422);
change("slides: reordered", $owner, "reorder_login_slides", ["order" => [3, 1, 2]]);
check("slides: new order", $order() === [3, 1, 2], json_encode($order()));
[, $r] = post("api/admin-login-slides.php", $owner, ["action" => "reorder", "order" => [1, 2, 3]]);
check("slides: the page still reorders", ($r["success"] ?? false) === true && $order() === [1, 2, 3], json_encode($r));
[, $r] = post("api/admin-login-slides.php", $owner, ["action" => "reorder", "order" => [1, 2]]);
check("slides: the page still refuses a stale order", ($r["success"] ?? true) === false, json_encode($r));

[$prep] = change("slide: removed", $owner, "remove_login_slide", ["slide" => 2]);
check("slide: removal says it can't be undone", strpos($prep["data"]["summary"] ?? "", "can't be undone") !== false);
check("slides: the gap is closed", $order() === [1, 3]
    && (int) one($pdo, "SELECT position FROM login_slides WHERE id = 3")["position"] === 1, json_encode($order()));

seed($pdo, "login_slides", [
    ["id" => 4, "image_path" => "uploads/login-slides/d.png", "position" => 2],
    ["id" => 5, "image_path" => "uploads/login-slides/e.png", "position" => 3],
    ["id" => 6, "image_path" => "uploads/login-slides/f.png", "position" => 4],
]);
refusedChange("slides: no room past the limit", $owner, "add_login_slide", ["image" => "https://8.8.8.8/s.png"], 409);

// ── The admin team ───────────────────────────────────────────────────────

refusedChange("role: never the owner role", $owner, "change_admin_role", ["admin" => 7, "role" => "owner"], 422);
refusedChange("role: never the owner's row", $owner, "change_admin_role", ["admin" => 1, "role" => "seo_admin"], 403);
refusedChange("role: already theirs", $owner, "change_admin_role", ["admin" => 7, "role" => "seo_admin"], 409);
refusedChange("role: not found", $owner, "change_admin_role", ["admin" => 99, "role" => "seo_admin"], 404);
[$prep] = change("role: changed", $owner, "change_admin_role", ["admin" => 7, "role" => "account_manager"]);
$tess = admin($pdo, 7);
check("role: saved, and they are signed out", $tess["role"] === "account_manager" && $tess["session_token"] === null, json_encode($tess));
check("role: the summary names them and says so", strpos($prep["data"]["summary"] ?? "", "Tess (t@w.test)") !== false
    && strpos($prep["data"]["summary"], "signed out") !== false, json_encode($prep));
[, $r] = post("api/admin-role.php", $owner, ["admin_id" => 7, "role" => "seo_admin"]);
check("role: the page still changes it", ($r["success"] ?? false) === true && admin($pdo, 7)["role"] === "seo_admin", json_encode($r));
[, $r] = post("api/admin-role.php", $owner, ["admin_id" => 1, "role" => "seo_admin"]);
check("role: the page still protects the owner", ($r["success"] ?? true) === false && admin($pdo, 1)["role"] === "owner", json_encode($r));

refusedChange("active: not yourself", $owner, "set_admin_active", ["admin" => 1, "active" => false], 403);
refusedChange("active: already on", $owner, "set_admin_active", ["admin" => 8, "active" => true], 409);
refusedChange("active: needs a value", $owner, "set_admin_active", ["admin" => 8], 422);
change("active: switched off", $owner, "set_admin_active", ["admin" => 8, "active" => false]);
check("active: off", (int) admin($pdo, 8)["active"] === 0);
refused("active: and they can't sign in", "clients.php", "tok-max", 401);
[, $r] = post("api/settings.php", $owner, ["action" => "toggle_admin_status", "id" => 8, "active" => 1]);
check("active: the page still switches them on", ($r["success"] ?? false) === true && (int) admin($pdo, 8)["active"] === 1, json_encode($r));

// Sam (super admin) manages Sean (5); Max (8) is a super admin too.
refusedChange("manager: must be a manager", $owner, "assign_admins_to_manager", ["manager" => 5, "admins" => []], 404);
refusedChange("manager: a super admin manages Admins only", $owner, "assign_admins_to_manager", ["manager" => 8, "admins" => [7, 2]], 422);
[$prep] = change("manager: assigned", $owner, "assign_admins_to_manager", ["manager" => 8, "admins" => [5, 7]]);
check("manager: Sean moves over, Tess joins", (int) admin($pdo, 5)["managed_by_admin_id"] === 8
    && (int) admin($pdo, 7)["managed_by_admin_id"] === 8, json_encode([admin($pdo, 5), admin($pdo, 7)]));
check("manager: the summary names them", strpos($prep["data"]["summary"] ?? "", "Sean, Tess") !== false, json_encode($prep));
change("manager: an account manager takes a super admin", $owner, "assign_admins_to_manager", ["manager" => 3, "admins" => [8]]);
check("manager: Max is Amy's", (int) admin($pdo, 8)["managed_by_admin_id"] === 3);
change("manager: cleared", $owner, "assign_admins_to_manager", ["manager" => 8, "admins" => []]);
check("manager: nobody under Max", admin($pdo, 5)["managed_by_admin_id"] === null && admin($pdo, 7)["managed_by_admin_id"] === null);
[, $r] = post("api/admin-manager-assignments.php", $owner, ["managerId" => 2, "adminIds" => [5, 1]]);
check("manager: the page still assigns, dropping who can't be", ($r["success"] ?? false) === true
    && (int) admin($pdo, 5)["managed_by_admin_id"] === 2 && admin($pdo, 1)["managed_by_admin_id"] === null, json_encode($r));

refusedChange("add admin: a real email", $owner, "add_admin", ["name" => "Nia", "position" => "SEO Specialist", "email" => "nope", "role" => "seo_admin"], 422);
refusedChange("add admin: not an owner", $owner, "add_admin", ["name" => "Nia", "position" => "SEO Specialist", "email" => "n@w.test", "role" => "owner"], 422);
refusedChange("add admin: not someone already here", $owner, "add_admin", ["name" => "Sam 2", "position" => "SEO Specialist", "email" => "S@w.test", "role" => "seo_admin"], 409);
refusedChange("add admin: a position is required", $owner, "add_admin", ["name" => "Nia", "email" => "n@w.test", "role" => "seo_admin"], 422);
[$prep, $done] = change("add admin: added", $owner, "add_admin", ["name" => "Nia", "position" => "SEO Specialist", "email" => "n@w.test", "role" => "super_admin"]);
$nia = one($pdo, "SELECT id, role, active, password, reset_token, reset_expires FROM admins WHERE email = 'n@w.test'");
check("add admin: saved, with a set-your-password link", $nia && $nia["role"] === "super_admin" && (int) $nia["active"] === 1
    && strlen((string) $nia["reset_token"]) === 64 && strtotime($nia["reset_expires"]) > time() + 71 * 3600, json_encode($nia));
check("add admin: says when the email didn't go out", ($done["data"]["result"]["emailed"] ?? null) === false
    && ($done["data"]["result"]["note"] ?? "") !== "", json_encode($done));
[, $r] = post("api/add-user.php", $owner, ["name" => "Page", "email" => "p@w.test", "password" => "secret1", "role" => "account_manager"]);
check("add admin: the page needs a position", ($r["success"] ?? true) === false
    && one($pdo, "SELECT COUNT(*) AS n FROM admins WHERE email = 'p@w.test'")["n"] == 0, json_encode($r));
[, $r] = post("api/add-user.php", $owner, ["name" => "Page", "position" => "Account Lead", "email" => "p@w.test", "password" => "secret1", "role" => "account_manager"]);
check("add admin: the page still adds one", ($r["success"] ?? false) === true
    && password_verify("secret1", one($pdo, "SELECT password FROM admins WHERE email = 'p@w.test'")["password"])
    && one($pdo, "SELECT position FROM admins WHERE email = 'p@w.test'")["position"] === "Account Lead", json_encode($r));
[, $r] = post("api/add-user.php", $owner, ["name" => "Page", "position" => "Account Lead", "email" => "p@w.test", "password" => "secret1"]);
check("add admin: the page refuses a repeat", ($r["success"] ?? true) === false, json_encode($r));
