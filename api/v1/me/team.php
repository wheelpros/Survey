<?php

/*
| GET me/team.php   my_team
|
| The people on the caller's account, and its pending invites if they own it.
| The same as the Team section of profile.html (clientTeamSnapshot() in client-team-writes.php).
*/

require_once __DIR__ . "/_me.php";
require_once __DIR__ . "/../../client-team-writes.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

v1Reply(200, clientTeamSnapshot($pdo, meRow($pdo, $p)));
