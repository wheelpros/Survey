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
