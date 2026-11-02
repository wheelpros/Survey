<?php

/*
| GET announcements.php?id=12   get_announcement
|
| One announcement in full, for staff - the notification only carries a
| preview of it. The same rule as the detail panel (announcementFor() in
| announcement-writes.php): the caller received it, or wrote it. Anyone
| else gets a 404, so nothing confirms it exists.
|
| Scope notifications:read: an announcement arrives as a notification.
| Sending one is send_announcement, through prepare/confirm.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../announcement-writes.php";

$p = v1Principal($pdo);
v1RequireStaff($p);
v1RequireMethod("GET");
v1RequireScope($p, "notifications:read");

ensureAnnouncementsTable($pdo);

$id = v1IntParam("id");
$row = $id === null ? null : announcementFor($pdo, "admin", $p["id"], $id);

if (!$row) {
    v1Error(404, "not_found", "No announcement with that id among the ones you were sent or wrote.");
}

v1Reply(200, announcementOut($row));
