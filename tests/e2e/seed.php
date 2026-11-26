<?php

/*
| The XSS canary's seed: the api/v1 seed (same people, posts and projects),
| then a marker payload in each user- or staff-typed field that a page
| renders as HTML. A payload that runs pushes its tag onto window.__xss;
| xss-canary.mjs fails any page where that list is not empty.
|
|   DB_NAME=..._test php tests/e2e/seed.php
|
| Ported from the 2026-11 audit's Stage 5 repro (repro-xss.js, repro-seo.js).
*/

$_SERVER["REQUEST_METHOD"] = "GET";
require __DIR__ . "/../api-v1/seed.php";

/** A payload that also breaks out of a quoted attribute. */
function xssMarker($tag)
{
    return "Z\"'><img src=x onerror=\"(window.__xss=window.__xss||[]).push('$tag')\">";
}

/*
| Links whose scheme only a regex check misses (audit U-05): the entity
| leaves a tab, a newline or a control character inside "javascript:",
| which the URL parser then drops.
*/
function hostileLinks($tag)
{
    $run = "(window.__xss=window.__xss||[]).push('$tag')";
    return "<p>"
        . "<a href=\"javascript:$run\">a</a> "
        . "<a href=\"java&#9;script:$run\">b</a> "
        . "<a href=\"java&#10;script:$run\">c</a> "
        . "<a href=\"&#1;javascript:$run\">d</a> "
        . "<a href=\" data:text/html,__xss\">e</a> "
        . "<a href=\"https://example.test/fine\">fine</a>"
        . "</p>";
}

// U-01: the name a client submits a form under, on responses.html.
$pdo->prepare("UPDATE survey_responses SET submitted_by_name = ? WHERE id = 200")
    ->execute(["Alice " . xssMarker("survey_responses.submitted_by_name")]);

// U-02, U-05: captions. 300 is a draft (staff pages), 303 is live (the client's Content page).
foreach ([300, 303] as $id) {
    $pdo->prepare("UPDATE content SET caption = ? WHERE id = ?")
        ->execute(["<p>Caption</p>" . xssMarker("content.caption.$id") . hostileLinks("content.caption.$id.href"), $id]);
}

// U-03, U-05: a published phase of Beta SEO (501), Bob's project.
$pdo->prepare("UPDATE project_tasks SET description = ? WHERE id = 601")
    ->execute(["<p>Phase</p>" . xssMarker("project_tasks.description") . hostileLinks("project_tasks.description.href")]);

// U-04: a client's upload file name, on survey.html.
$pdo->prepare("UPDATE survey_uploaded_files SET original_name = ? WHERE response_id = 200")
    ->execute(["logo" . xssMarker("survey_uploaded_files.original_name") . ".png"]);

// A client chat: Alice writes to Amy, her teammate Tia's name is a payload too.
$pdo->prepare("INSERT INTO users (id, name, email, approved, account_owner_id) VALUES (30, ?, 'tia@acme.test', 1, 10)")
    ->execute(["Tia " . xssMarker("users.name.teammate")]);
$pdo->prepare("INSERT INTO client_messages (user_id, sender_kind, sender_id, sender_name, body) VALUES (10, 'user', 10, 'Alice', ?)")
    ->execute(["Hi Amy " . xssMarker("client_messages.body") . " javascript:(window.__xss=window.__xss||[]).push('client_messages.href') https://example.test/ok"]);
$pdo->prepare("INSERT INTO client_messages (user_id, sender_kind, sender_id, sender_name, body) VALUES (10, 'user', 30, 'Tia', 'Hello from Tia')")
    ->execute();

// Bob signs in to see his project.
$pdo->exec("UPDATE users SET session_token = 'tok-bob' WHERE id = 11");

echo "seeded\n";
