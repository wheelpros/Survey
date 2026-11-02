<?php

/*
| GET me/announcements.php?id=12   get_my_announcement
|
| One announcement W|ZONE sent this client, in full - the notification only
| carries a preview. Only one they received (announcementFor() in
| announcement-writes.php); anything else is a 404.
*/

require_once __DIR__ . "/_me.php";
require_once __DIR__ . "/../../announcement-writes.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureAnnouncementsTable($pdo);

$id = v1IntParam("id");
$row = $id === null ? null : announcementFor($pdo, "user", $p["id"], $id);

if (!$row) {
    v1Error(404, "not_found", "No announcement with that id among the ones you were sent.");
}

v1Reply(200, announcementOut($row));
