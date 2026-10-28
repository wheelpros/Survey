<?php

/*
| Positions - required by run.php after phases.php, sharing its scope. The
| job title the owner gives an admin (admins.position), shown in the sidebar
| and the account popup in place of the name: api/admin-position.php to change
| it, the same value on the session reply, the Settings list and get_settings,
| and v1's set_admin_position. (add_admin / add-user.php require one: see
| settings.php.)
|
| Sean (5) is an Admin, Sam (2) a Super Admin; the owner is Olivia (1).
*/

/** An admin's position, as stored. */
function positionOf(PDO $pdo, $adminId)
{
    return one($pdo, "SELECT position FROM admins WHERE id = ?", [$adminId])["position"];
}

check("position: nobody has one before the owner sets it", positionOf($pdo, 5) === null);

[, $r] = post("api/admin-position.php", $owner, ["admin_id" => 5, "position" => "  SEO   Specialist "]);
check("position: the owner sets it, tidied", ($r["success"] ?? false) && ($r["position"] ?? "") === "SEO Specialist"
    && positionOf($pdo, 5) === "SEO Specialist", json_encode($r));

[, $r] = post("api/admin-position.php", $owner, ["admin_id" => 1, "position" => "Founder"]);
check("position: the owner can set their own", ($r["success"] ?? false) && positionOf($pdo, 1) === "Founder", json_encode($r));

foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [, $r] = post("api/admin-position.php", $token, ["admin_id" => 5, "position" => "Hacker"]);
    check("position: a $who can't set it", ($r["success"] ?? true) === false && positionOf($pdo, 5) === "SEO Specialist", json_encode($r));
}
[, $r] = post("api/admin-position.php", "tok-nobody", ["admin_id" => 5, "position" => "x"]);
check("position: a bad token is refused", ($r["success"] ?? true) === false, json_encode($r));

[, $r] = post("api/admin-position.php", $owner, ["admin_id" => 5, "position" => "   "]);
check("position: not blank", ($r["success"] ?? true) === false && positionOf($pdo, 5) === "SEO Specialist", json_encode($r));
[, $r] = post("api/admin-position.php", $owner, ["admin_id" => 5, "position" => str_repeat("x", 101)]);
check("position: at most 100 characters", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/admin-position.php", $owner, ["admin_id" => 999, "position" => "Ghost"]);
check("position: an admin that exists", ($r["success"] ?? true) === false, json_encode($r));

// Everywhere the pages read the admin from.
$reply = json_decode((string) @file_get_contents("http://127.0.0.1:$port/api/admin-session.php", false, stream_context_create([
    "http" => ["header" => "Authorization: Bearer $seo", "ignore_errors" => true, "timeout" => 10],
])), true);
check("position: the session reply carries it", ($reply["valid"] ?? false) && ($reply["admin"]["position"] ?? null) === "SEO Specialist", json_encode($reply));
$reply = json_decode((string) @file_get_contents("http://127.0.0.1:$port/api/admin-session.php", false, stream_context_create([
    "http" => ["header" => "Authorization: Bearer $am", "ignore_errors" => true, "timeout" => 10],
])), true);
check("position: null for an admin without one", ($reply["valid"] ?? false) && array_key_exists("position", $reply["admin"]) && $reply["admin"]["position"] === null, json_encode($reply));

[, $list] = pageGet("api/get-admins.php", $owner);
$sean = array_values(array_filter($list["admins"] ?? [], function ($a) { return (int) $a["id"] === 5; }));
check("position: the Settings list carries it", count($sean) === 1 && $sean[0]["position"] === "SEO Specialist", json_encode($list));

[, $s] = get("settings.php", $owner);
$seanV1 = array_values(array_filter($s["data"]["admins"] ?? [], function ($a) { return $a["id"] === 5; }));
check("position: get_settings carries it", count($seanV1) === 1 && ($seanV1[0]["untrusted_content"]["position"] ?? null) === "SEO Specialist", json_encode($s));

// v1 set_admin_position
refusedChange("position v1: only the owner", $am, "set_admin_position", ["admin" => 5, "position" => "x"], 403);
refusedChange("position v1: not blank", $owner, "set_admin_position", ["admin" => 5, "position" => " "], 422);
refusedChange("position v1: already theirs", $owner, "set_admin_position", ["admin" => 5, "position" => "SEO Specialist"], 409);
refusedChange("position v1: an admin that exists", $owner, "set_admin_position", ["admin" => 999, "position" => "Ghost"], 404);
[$prep] = change("position v1: set", $owner, "set_admin_position", ["admin" => 2, "position" => "Creative Director"]);
check("position v1: saved, and the summary says it replaces the name", positionOf($pdo, 2) === "Creative Director"
    && strpos($prep["data"]["summary"] ?? "", "replaces their name") !== false, json_encode($prep));

// ── The Edit popup: name, email, position and role in one save ──────────

/** One admin row. */
function adminRowOf(PDO $pdo, $adminId)
{
    return one($pdo, "SELECT name, email, position, role, session_token FROM admins WHERE id = ?", [$adminId]);
}

// Sean (5) is signed in as tok-seo, so a role change must clear that.
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => " Sean Q ", "email" => "sean.q@w.test",
    "position" => "Head of SEO", "role" => "seo_admin"]);
$sean = adminRowOf($pdo, 5);
check("edit: the owner changes name, email and position", ($r["success"] ?? false) && $sean["name"] === "Sean Q"
    && $sean["email"] === "sean.q@w.test" && $sean["position"] === "Head of SEO", json_encode($r));
check("edit: nobody is signed out when the role stays", ($r["signed_out"] ?? null) === false && $sean["session_token"] === "tok-seo", json_encode($r));

[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean Q", "email" => "sean.q@w.test",
    "position" => "Head of SEO", "role" => "super_admin"]);
$sean = adminRowOf($pdo, 5);
check("edit: a changed role signs them out", ($r["success"] ?? false) && ($r["signed_out"] ?? null) === true
    && $sean["role"] === "super_admin" && $sean["session_token"] === null, json_encode($r));
// Put him back for the tests after this file.
post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "se@w.test", "position" => "SEO Specialist", "role" => "seo_admin"]);
$pdo->exec("UPDATE admins SET session_token = 'tok-seo' WHERE id = 5");

[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "A@W.test", "position" => "SEO", "role" => "seo_admin"]);
check("edit: not another admin's email", ($r["success"] ?? true) === false && adminRowOf($pdo, 5)["email"] === "se@w.test", json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "se@w.test", "position" => "SEO", "role" => "seo_admin"]);
check("edit: keeping their own email is fine", ($r["success"] ?? false) === true, json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "nope", "position" => "SEO", "role" => "seo_admin"]);
check("edit: a real email", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "  ", "email" => "se@w.test", "position" => "SEO", "role" => "seo_admin"]);
check("edit: a name", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "se@w.test", "position" => "", "role" => "seo_admin"]);
check("edit: a position", ($r["success"] ?? true) === false && adminRowOf($pdo, 5)["position"] === "SEO", json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "se@w.test", "position" => "SEO", "role" => "owner"]);
check("edit: never the owner role", ($r["success"] ?? true) === false && adminRowOf($pdo, 5)["role"] === "seo_admin", json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 1, "name" => "Olivia", "email" => "o@w.test", "position" => "Founder", "role" => "seo_admin"]);
check("edit: never the owner's row", ($r["success"] ?? true) === false, json_encode($r));
[, $r] = post("api/admin-update.php", $owner, ["admin_id" => 999, "name" => "x", "email" => "x@w.test", "position" => "x", "role" => "seo_admin"]);
check("edit: an admin that exists", ($r["success"] ?? true) === false, json_encode($r));
foreach (["super admin" => $sa, "account manager" => $am, "admin" => $seo] as $who => $token) {
    [, $r] = post("api/admin-update.php", $token, ["admin_id" => 5, "name" => "Hacked", "email" => "h@w.test", "position" => "x", "role" => "seo_admin"]);
    check("edit: a $who can't", ($r["success"] ?? true) === false && adminRowOf($pdo, 5)["name"] === "Sean", json_encode($r));
}
post("api/admin-update.php", $owner, ["admin_id" => 5, "name" => "Sean", "email" => "se@w.test", "position" => "SEO Specialist", "role" => "seo_admin"]);

// ── update_admin (api/v1): the Edit popup's save ────────────────────────

refusedChange("update_admin v1: only the owner", $am, "update_admin", ["admin" => 5, "name" => "Hacked"], 403);
refusedChange("update_admin v1: something to change", $owner, "update_admin", ["admin" => 5], 422);
refusedChange("update_admin v1: nothing new", $owner, "update_admin", ["admin" => 5, "name" => "Sean"], 409);
refusedChange("update_admin v1: not another admin's email", $owner, "update_admin", ["admin" => 5, "email" => "a@w.test"], 409);
refusedChange("update_admin v1: never the owner's row", $owner, "update_admin", ["admin" => 1, "name" => "Olivia 2"], 403);
refusedChange("update_admin v1: never the owner role", $owner, "update_admin", ["admin" => 5, "role" => "owner"], 422);

[$prep] = change("update_admin v1: name and email", $owner, "update_admin", ["admin" => 5, "name" => "Sean Q", "email" => "sean.q@w.test"]);
$sean = adminRowOf($pdo, 5);
check("update_admin v1: saved, nobody signed out", $sean["name"] === "Sean Q" && $sean["email"] === "sean.q@w.test"
    && $sean["position"] === "SEO Specialist" && $sean["session_token"] === "tok-seo"
    && strpos($prep["data"]["summary"] ?? "", "Nobody is signed out") !== false, json_encode($prep));

[$prep] = change("update_admin v1: a role change", $owner, "update_admin", ["admin" => 5, "role" => "super_admin"]);
check("update_admin v1: a role change signs them out", adminRowOf($pdo, 5)["role"] === "super_admin" && adminRowOf($pdo, 5)["session_token"] === null
    && strpos($prep["data"]["summary"] ?? "", "signed out") !== false, json_encode($prep));

// Put Sean back as the files after this one expect him.
$pdo->exec("UPDATE admins SET name = 'Sean', email = 'se@w.test', role = 'seo_admin', session_token = 'tok-seo' WHERE id = 5");

[, $me] = get("me.php", $seo);
check("whoami: my position", ($me["data"]["position"] ?? null) === "SEO Specialist", json_encode($me));
[, $me] = get("me.php", $am);
check("whoami: null without one", array_key_exists("position", $me["data"] ?? []) && $me["data"]["position"] === null, json_encode($me));
