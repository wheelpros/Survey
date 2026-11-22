<?php

/*
| Rich text is scrubbed when it is written (audit U-02, U-03, U-05) -
| required by run.php after writes.php, whose one() it uses.
|
| Captions (api/admin-content.php) and phase descriptions (api/project-tasks.php
| through task-writes.php) keep the editor's formatting, and lose anything
| that would run: handlers, script, and links outside http(s)/mailto.
*/

$hostile = '<p dir="rtl" onclick="x()">مرحبا <b>bold</b> <img src=x onerror="alert(1)"></p>'
    . '<div style="text-align: center; color: red">Centred</div>'
    . '<script>alert(2)</script>'
    . '<a href="java&#9;script:alert(3)">tab</a> <a href="javascript:alert(4)">js</a> '
    . '<a href="https://example.test/ok">ok</a>';

$clean = function ($stored) {
    return strpos($stored, "onerror") === false && strpos($stored, "onclick") === false
        && strpos($stored, "<script") === false && stripos($stored, "script:") === false
        && strpos($stored, "color") === false;
};
$kept = function ($stored) {
    return strpos($stored, '<p dir="rtl">مرحبا <b>bold</b> </p>') !== false
        && strpos($stored, '<div style="text-align: center;">Centred</div>') !== false
        && strpos($stored, 'href="https://example.test/ok"') !== false;
};

[$status, $r] = post("api/admin-content.php", $am, ["title" => "Rich caption", "client" => "Acme Ltd", "status" => "draft",
    "content_type" => "articles", "type_label" => "Articles", "caption" => $hostile], [], true);
$stored = (string) (one($pdo, "SELECT caption FROM content WHERE title = 'Rich caption'")["caption"] ?? "");
check("rich text: a caption is stored without handlers, script or script links", $status === 200 && $clean($stored), "HTTP $status $stored");
check("rich text: and keeps its formatting, alignment, direction and real links", $kept($stored), $stored);
$pdo->exec("DELETE FROM content WHERE title = 'Rich caption'");

[$status, $r] = post("api/project-tasks.php", $owner, ["project_id" => 500, "title" => "Rich phase", "status" => "draft",
    "description" => $hostile], [], true);
$stored = (string) (one($pdo, "SELECT description FROM project_tasks WHERE title = 'Rich phase'")["description"] ?? "");
check("rich text: a phase description is stored without handlers, script or script links", ($r["success"] ?? false) && $clean($stored),
    "HTTP $status $stored");
check("rich text: and keeps its formatting too", $kept($stored), $stored);
$pdo->exec("DELETE FROM project_tasks WHERE title = 'Rich phase'");
