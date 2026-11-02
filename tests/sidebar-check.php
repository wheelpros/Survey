<?php
/*
|------------------------------------------------------------------------------
| Every admin page draws its sidebar links from assets/admin-nav.js
|------------------------------------------------------------------------------
|
| The links used to be copied into each page, and the copies drifted. This
| fails if a page with the admin sidebar carries its own links again, or is
| missing one of the scripts the sidebar needs.
|
|   php tests/sidebar-check.php
|
*/

$root = dirname(__DIR__);
$failures = [];
$checked = 0;

foreach (glob($root . "/*.html") as $path) {
    $html = file_get_contents($path);
    $page = basename($path);

    if (strpos($html, 'class="admin-sidebar"') === false) {
        continue;
    }
    $checked++;

    if (!preg_match('#<nav class="side-nav"></nav>\s*<script src="assets/admin-nav\.js\?v=\d+"></script>#', $html)) {
        $failures[] = "$page: the nav must be empty and followed by assets/admin-nav.js";
    }

    foreach (["permissions.js", "role-nav.js", "shell.js", "notifications.js", "messages-badge.js"] as $script) {
        if (strpos($html, "assets/$script") === false) {
            $failures[] = "$page: missing assets/$script";
        }
    }

    // adminCan() has to exist before admin-nav.js runs, so it goes in <head>.
    $head = strstr($html, "</head>", true);
    if ($head === false || strpos($head, "assets/permissions.js") === false) {
        $failures[] = "$page: assets/permissions.js must load in <head>";
    }
}

if ($checked === 0) {
    $failures[] = "no admin pages found";
}

if ($failures) {
    echo "Sidebar check failed:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}

echo "Sidebar check: $checked admin pages OK\n";
