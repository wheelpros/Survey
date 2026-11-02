<?php

/*
| Announcements through api/v1 - required by run.php, sharing its scope.
|
|   1. send_announcement: only for whoever holds announcements.send (the
|      Director here), to the team or to clients, everyone or named people.
|   2. Each recipient's notification carries the announcement_id, and
|      get_announcement / get_my_announcement open it - for a recipient or
|      its author only.
*/

// ── 1. Sending ───────────────────────────────────────────────────────────

refusedChange("v1 announcements: Sam can't send without the permission", $sa, "send_announcement",
    ["audience" => "team", "title" => "Hello"], 403);
refusedChange("v1 announcements: a title is required", $owner, "send_announcement",
    ["audience" => "team", "title" => " "], 422);
refusedChange("v1 announcements: specific needs recipients", $owner, "send_announcement",
    ["audience" => "team", "scope" => "specific", "title" => "Hi"], 422);
refusedChange("v1 announcements: nobody left is refused", $owner, "send_announcement",
    ["audience" => "team", "scope" => "specific", "recipients" => [6], "title" => "Hi"], 400);

[$prep, $done] = change("v1 announcements: to Sam and Amy", $owner, "send_announcement", [
    "audience" => "team", "scope" => "specific", "recipients" => [2, 3, 6, 99999],
    "title" => "Office closed Friday", "description" => "Back on Monday.", "event_date" => day("+4"),
]);
check("v1 announcements: the summary names the people", strpos($prep["data"]["summary"] ?? "", "Amy, Sam") !== false,
    json_encode($prep));
$teamAnnouncement = (int) ($done["data"]["result"]["announcement_id"] ?? 0);
check("v1 announcements: only active admins received it", ($done["data"]["result"]["sent"] ?? null) === 2
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE announcement_id = ?", [$teamAnnouncement])["n"] === 2,
    json_encode($done));

[, $done] = change("v1 announcements: to every client", $owner, "send_announcement",
    ["audience" => "client", "title" => "New portal features"]);
$clientAnnouncement = (int) ($done["data"]["result"]["announcement_id"] ?? 0);

// ── 2. Reading ───────────────────────────────────────────────────────────

[, $r] = get("notifications.php", $sa);
$row = array_values(array_filter($r["data"]["items"] ?? [], function ($n) use ($teamAnnouncement) {
    return $n["announcement_id"] === $teamAnnouncement;
}));
check("v1 announcements: the notification carries its id", count($row) === 1 && $row[0]["is_announcement"] === true,
    json_encode($r));

[$status, $r] = get("announcements.php?id=$teamAnnouncement", $sa);
check("v1 announcements: a recipient opens it", $status === 200
    && $r["data"]["untrusted_content"]["title"] === "Office closed Friday"
    && $r["data"]["untrusted_content"]["description"] === "Back on Monday." && $r["data"]["created_by_name"] === "Olivia",
    "HTTP $status " . json_encode($r));
$fixtures["get_announcement"] = $r["data"] ?? null;

[$status] = get("announcements.php?id=$teamAnnouncement", $owner);
check("v1 announcements: its author opens it", $status === 200, "HTTP $status");
[$status] = get("announcements.php?id=$teamAnnouncement", $seo);
check("v1 announcements: someone it wasn't sent to gets a 404", $status === 404, "HTTP $status");
[$status] = get("announcements.php?id=$clientAnnouncement", $sa);
check("v1 announcements: a client announcement isn't the team's", $status === 404, "HTTP $status");

[$status, $r] = get("me/announcements.php?id=$clientAnnouncement", "tok-client");
check("v1 announcements: a client opens theirs", $status === 200 && $r["data"]["audience"] === "client",
    "HTTP $status " . json_encode($r));
$fixtures["get_my_announcement"] = $r["data"] ?? null;
[$status] = get("me/announcements.php?id=$teamAnnouncement", "tok-client");
check("v1 announcements: a client can't open the team's", $status === 404, "HTTP $status");
