<?php

/*
| Settings - what the owner sees on settings.html.
|
|   GET settings.php     get_settings
|
| Scope settings:read, the owner's alone (SCOPE_PERMISSIONS in auth.php). Never
| returns a password, password hash, session or reset token, or the files
| password - only whether things are set.
|
| Admin names and emails, and slide captions, were typed by people, so they
| come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../admin-settings-writes.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "settings:read");

$website = getSiteSetting($pdo, "website_url", "");
$logo = siteLogoPath();
$director = adminProfile($pdo, $p["id"]);

v1Reply(200, [
    // The card at the top of Settings. Only name and position change
    // (update_my_profile); the email and the role are fixed there.
    "director" => [
        "id" => $director["id"],
        "role" => $director["role"],
        "role_name" => $director["role_label"],
        "avatar_url" => $director["avatar_path"] ? APP_URL . "/" . $director["avatar_path"] : null,
        "untrusted_content" => [
            "name" => $director["name"],
            "email" => $director["email"],
            "position" => v1NullableString($director["position"]),
        ],
    ],
    "website_address" => $website === "" ? null : $website,
    "website_link" => v1NullableString(normaliseWebUrl($website)),
    "logo_url" => is_file($logo) ? APP_URL . "/logo.png?v=" . filemtime($logo) : null,
    "clients_may_use_ai" => clientsMayUseMcp($pdo),
    "login_slides_max" => LOGIN_SLIDES_MAX,
    "login_slide_min_size" => LOGIN_SLIDE_MIN_WIDTH . "x" . LOGIN_SLIDE_MIN_HEIGHT,
    "login_slides" => array_map(function ($s) {
        return [
            "id" => $s["id"],
            "position" => $s["position"],
            "image_url" => APP_URL . "/" . ltrim($s["image_path"], "/"),
            "untrusted_content" => [
                "title" => v1NullableString($s["title"]),
                "subtitle" => v1NullableString($s["subtitle"]),
            ],
        ];
    }, listLoginSlides($pdo)),
    "admins" => array_map(function ($a) {
        return [
            "id" => (int) $a["id"],
            "role" => $a["role"],
            "role_name" => adminRoleName($a["role"]),
            "active" => (int) $a["active"] === 1,
            "managed_by" => $a["managed_by_admin_id"] === null ? null : (int) $a["managed_by_admin_id"],
            "avatar_url" => !empty($a["avatar_path"]) ? APP_URL . "/" . $a["avatar_path"] : null,
            "untrusted_content" => [
                "name" => (string) $a["name"],
                "email" => (string) $a["email"],
                "position" => v1NullableString($a["position"] ?? null),
                "managed_by_name" => v1NullableString($a["managed_by_name"]),
            ],
        ];
    }, listTeamAdmins($pdo)),
]);
