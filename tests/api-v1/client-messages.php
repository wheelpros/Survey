<?php

/*
| Client messages - required by run.php after messages, sharing its scope.
|
|   1. A client account's chat: everyone on the account and its account
|      managers read and write it; nobody else on the staff side does - not
|      a super admin or admin on the same client.
|   1b. The Director reads every chat, never writes or marks read, and each
|      opening is logged in Tracking without the words.
|   2. No account manager yet: the Director is the team side - reads,
|      writes, has it unread, is emailed - until one is assigned.
|   3. The chat belongs to the account: a new account manager reads the
|      history, one taken off the team loses it.
|   3b. "Write to clients in Messages" decides who is on the team side.
|   4. Unread, Seen, and the staff badge counting client chats.
|   4b. Editing and deleting your own message, for 5 minutes, seen by
|      everyone on their next poll.
|   5. The email for a message left unread for 15 minutes: once, then
|      quiet until the chat is opened.
|   6. Through api/v1 (the MCP), for the client and for the account manager.
|   7. Nothing in Tracking's Changes or the notifications inbox.
*/

require_once $root . "/api/client-message-writes.php";

function cmGet($query, $token)
{
    return pageGet("api/client-messages.php?" . $query, $token);
}

function cmPost($token, array $body)
{
    return post("api/client-messages.php", $token, $body);
}

function amGet($query, $token)
{
    return pageGet("api/admin-messages.php?" . $query, $token);
}

function amPost($token, array $body)
{
    return post("api/admin-messages.php", $token, $body);
}

ensureClientMessageTables($pdo);

// Only this module's own calls run the email sweep (section 5); the count
// checks the pages make would otherwise race it.
$pdo->exec("UPDATE client_message_jobs SET ran_at = NOW() + INTERVAL 1 DAY WHERE name = 'email_sweep'");

// Tia is Alice's teammate on Acme (10); Bob (11) signs in too; Nora (13) has
// only Sam on her team, so no account manager.
$pdo->exec("UPDATE users SET session_token = 'tok-bob' WHERE id = 11");
seed($pdo, "users", [
    ["id" => 30, "name" => "Tia", "email" => "tia@acme.test", "approved" => 1, "session_token" => "tok-tia", "account_owner_id" => 10],
    ["id" => 13, "name" => "Nora", "email" => "nora@north.test", "approved" => 1, "session_token" => "tok-nora", "company_name" => "North"],
]);
seed($pdo, "admin_user_assignments", [["admin_id" => 2, "user_id" => 13]]);

$alice = "tok-client";
$tia = "tok-tia";

// ── 1. Who is in the chat ───────────────────────────────────────────────

[$status, $r] = cmGet("action=thread", $alice);
check("client messages: Alice's chat is with Amy, empty", $status === 200 && array_column($r["managers"], "name") === ["Amy"]
    && $r["can_send"] === true && $r["messages"] === [], "HTTP $status " . json_encode($r));

[$status, $r] = cmPost($alice, ["action" => "send", "body" => "  Hi Amy, can we move the launch?  "]);
check("client messages: Alice writes", $status === 200 && $r["message_row"]["mine"] === true
    && $r["message_row"]["side"] === "client" && $r["message_row"]["body"] === "Hi Amy, can we move the launch?", "HTTP $status " . json_encode($r));
$aliceMessage = $r["message_row"]["id"] ?? 0;

[, $r] = cmGet("action=thread", $tia);
$m = $r["messages"][0] ?? [];
check("client messages: her teammate shares the chat", count($r["messages"]) === 1 && $m["mine"] === false
    && $m["side"] === "client" && $m["sender"]["name"] === "Alice", json_encode($r));

[, $r] = amGet("action=client_list", $am);
$c = $r["chats"][0] ?? [];
check("client messages: Amy lists Acme with one unread", ($r["available"] ?? null) === true && count($r["chats"]) === 1
    && $c["client"]["id"] === 10 && $c["unread"] === 1 && $c["last"]["preview"] === "Hi Amy, can we move the launch?"
    && $c["last"]["sender"] === "Alice", json_encode($r));

[$status, $r] = amGet("action=client_thread&client=10", $am);
check("client messages: Amy reads it", $status === 200 && count($r["messages"]) === 1 && $r["messages"][0]["side"] === "client"
    && array_column($r["people"], "name") === ["Alice", "Tia"], "HTTP $status " . json_encode($r));

foreach ([
    "a super admin on the client's team" => $sa,
    "an admin on the client's team" => $seo,
    "another client's account manager" => $am2,
] as $who => $token) {
    [$status] = amGet("action=client_thread&client=10", $token);
    check("client messages: $who can't open it", $status === 404, "HTTP $status");
    [$status] = amPost($token, ["action" => "client_send", "client" => 10, "body" => "Hello"]);
    check("client messages: $who can't write in it", $status === 404, "HTTP $status");
}
[, $r] = amGet("action=client_list", $sa);
check("client messages: a super admin has no client chats", ($r["available"] ?? null) === false && ($r["chats"] ?? null) === [], json_encode($r));

// ── 1b. The Director reads, and it is logged ───────────────────────────

$chatReads = function () use ($pdo) {
    return (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE action = 'client.chat_read'")["n"];
};

[, $r] = amGet("action=client_list", $owner);
$acme = null;
foreach ($r["chats"] ?? [] as $c) {
    if ($c["client"]["id"] === 10) {
        $acme = $c;
    }
}
$access = [];
foreach ($r["chats"] ?? [] as $c) {
    $access[$c["client"]["id"]] = $c["access"];
}
ksort($access);
check("client messages: the Director lists every client's chat, read only where there is an account manager", ($r["available"] ?? null) === true
    && $access === [10 => "observer", 11 => "observer", 13 => "team"]
    && count($r["chats"]) === count(allClientChatIds($pdo)) && $acme && $acme["unread"] === 0
    && $acme["last"]["preview"] === "Hi Amy, can we move the launch?", json_encode($r));

$before = $chatReads();
[$status, $r] = amGet("action=client_thread&client=10&open=1", $owner);
check("client messages: the Director opens Acme's chat", $status === 200 && ($r["access"] ?? null) === "observer"
    && count($r["messages"]) === 1 && $r["messages"][0]["mine"] === false, "HTTP $status " . json_encode($r));
$event = one($pdo, "SELECT actor_admin_id, client_id, target_label, summary FROM admin_events WHERE action = 'client.chat_read' ORDER BY id DESC LIMIT 1");
check("client messages: the visit is in Tracking, who and which client", $chatReads() === $before + 1
    && (int) $event["actor_admin_id"] === 1 && (int) $event["client_id"] === 10 && $event["target_label"] === "Acme Ltd"
    && strpos($event["summary"], "launch") === false, json_encode($event));

amGet("action=client_thread&client=10&after=" . $aliceMessage, $owner);
amGet("action=client_thread&client=10", $owner);
check("client messages: polling the open chat logs nothing more", $chatReads() === $before + 1);

[$status, $r] = amPost($owner, ["action" => "client_send", "client" => 10, "body" => "Hello from the Director"]);
check("client messages: the Director can't write in it", $status === 403 && strpos($r["message"], "as the Director") !== false,
    "HTTP $status " . json_encode($r));
[$status] = amPost($owner, ["action" => "client_read", "client" => 10]);
check("client messages: nor mark it read", $status === 403, "HTTP $status");
check("client messages: the Director leaves no read marker",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM client_message_reads WHERE reader_kind = 'admin' AND reader_id = 1")["n"] === 0);
[, $r] = amGet("action=count", $owner);
check("client messages: a chat the Director only reads isn't unread for them", ($r["client_unread"] ?? null) === 0, json_encode($r));
[$status] = amGet("action=client_thread&client=999&open=1", $owner);
check("client messages: an unknown client is not found for the Director too", $status === 404, "HTTP $status");
[, $r] = amGet("action=client_thread&client=10", $am);
check("client messages: Amy's own visit is not logged, and her chat stays hers", ($r["access"] ?? null) === "team" && $chatReads() === $before + 1, json_encode($r));

[, $r] = cmGet("action=thread", "tok-bob");
check("client messages: another client's chat is their own, empty", ($r["messages"] ?? null) === []
    && array_column($r["managers"], "name") === ["Andy"], json_encode($r));
[$status] = amGet("action=client_thread&client=999", $am);
check("client messages: an unknown client is not found", $status === 404, "HTTP $status");

[$status] = cmGet("action=thread", $am);
check("client messages: staff are refused on the client's page", $status === 403, "HTTP $status");
[$status] = amGet("action=client_list", $alice);
check("client messages: a client is refused on the staff page", $status === 403, "HTTP $status");
[$status] = cmGet("action=thread", $mcpToken);
check("client messages: an AI token is refused on the page", $status === 403, "HTTP $status");
[$status] = cmGet("action=thread", "nobody");
check("client messages: no session is refused", $status === 401, "HTTP $status");

foreach (["an empty message" => "   ", "a message over 2000 characters" => str_repeat("x", 2001)] as $what => $body) {
    [$status] = cmPost($alice, ["action" => "send", "body" => $body]);
    check("client messages: $what is refused", $status === 400, "HTTP $status");
}

// ── 2. No account manager yet: the Director answers ────────────────────

[, $r] = cmGet("action=thread", "tok-nora");
check("client messages: without an account manager the Director answers", ($r["managers"] ?? null) === []
    && ($r["director"]["name"] ?? null) === "Olivia" && $r["can_send"] === true, json_encode($r));
[$status, $r] = cmPost("tok-nora", ["action" => "send", "body" => "Hello? Anyone there?"]);
check("client messages: so Nora can still write", $status === 200 && $r["message_row"]["side"] === "client", "HTTP $status " . json_encode($r));
$noraMessage = $r["message_row"]["id"] ?? 0;

[, $r] = amGet("action=count", $owner);
check("client messages: it is unread for the Director", ($r["client_unread"] ?? null) === 1 && ($r["client_latest_id"] ?? 0) === $noraMessage, json_encode($r));
$north = null;
foreach (amGet("action=client_list", $owner)[1]["chats"] ?? [] as $c) {
    if ($c["client"]["id"] === 13) {
        $north = $c;
    }
}
check("client messages: the Director's list has North to answer", $north && $north["access"] === "team" && $north["unread"] === 1, json_encode($north));

$before = $chatReads();
[$status, $r] = amGet("action=client_thread&client=13&open=1", $owner);
check("client messages: the Director opens it as its team side, not logged", $status === 200 && $r["access"] === "team"
    && $r["managers"] === [] && $chatReads() === $before, "HTTP $status " . json_encode($r));
[$status, $r] = amPost($owner, ["action" => "client_send", "client" => 13, "body" => "Hi Nora, I'm looking after you for now."]);
check("client messages: and answers", $status === 200 && $r["message_row"]["mine"] === true && $r["message_row"]["side"] === "team",
    "HTTP $status " . json_encode($r));
[, $r] = cmGet("action=thread", "tok-nora");
check("client messages: Nora sees the Director has read hers", ($r["seen_by"][0]["name"] ?? null) === "Olivia"
    && $r["seen_by"][0]["last_read_id"] >= $noraMessage, json_encode($r["seen_by"] ?? null));

$noraMail = [];
$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 20 MINUTE WHERE user_id = 13");
cmPost("tok-nora", ["action" => "send", "body" => "Thanks!"]);
$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 20 MINUTE WHERE user_id = 13");
clientMessageEmailSweep($pdo, function (array $to, $subject) use (&$noraMail) {
    $noraMail[] = $to[0]["email"];
    return 1;
});
check("client messages: the Director is emailed about it after 15 minutes", $noraMail === ["o@w.test"], json_encode($noraMail));

seed($pdo, "admin_user_assignments", [["admin_id" => 4, "user_id" => 13]]);
[, $r] = cmGet("action=thread", "tok-nora");
check("client messages: once an account manager is assigned, they are who Nora writes to", array_column($r["managers"], "name") === ["Andy"]
    && $r["director"] === null, json_encode($r));
[$status, $r] = amGet("action=client_thread&client=13&open=1", $owner);
check("client messages: and the Director goes back to reading only", $status === 200 && $r["access"] === "observer" && $chatReads() === $before + 1,
    "HTTP $status " . json_encode($r));
[$status] = amPost($owner, ["action" => "client_send", "client" => 13, "body" => "Still here"]);
check("client messages: so can't write in it any more", $status === 403, "HTTP $status");
[, $r] = amGet("action=count", $owner);
check("client messages: nor has it unread", ($r["client_unread"] ?? null) === 0, json_encode($r));

// North's chat stays out of the sweeps below.
$pdo->exec("DELETE FROM admin_user_assignments WHERE user_id = 13 AND admin_id = 4");
$pdo->exec("DELETE FROM client_messages WHERE user_id = 13");
$pdo->exec("DELETE FROM client_message_reads WHERE user_id = 13");

// ── 3. The chat belongs to the account ─────────────────────────────────

seed($pdo, "admin_user_assignments", [["admin_id" => 4, "user_id" => 10]]);
[$status, $r] = amGet("action=client_thread&client=10", $am2);
check("client messages: a new account manager reads the history", $status === 200 && count($r["messages"]) === 1, "HTTP $status");
[, $r] = cmGet("action=thread", $alice);
check("client messages: the client sees both account managers", array_column($r["managers"], "name") === ["Amy", "Andy"], json_encode($r));

$pdo->exec("DELETE FROM admin_user_assignments WHERE admin_id = 3 AND user_id = 10");
[$status] = amGet("action=client_thread&client=10", $am);
check("client messages: one taken off the team loses it", $status === 404, "HTTP $status");
seed($pdo, "admin_user_assignments", [["admin_id" => 3, "user_id" => 10]]);
$pdo->exec("DELETE FROM admin_user_assignments WHERE admin_id = 4 AND user_id = 10");

$pdo->exec("UPDATE admins SET active = 0 WHERE id = 3");
[, $r] = cmGet("action=thread", $alice);
check("client messages: a switched-off account manager leaves the chat to the Director", ($r["managers"] ?? null) === []
    && ($r["director"]["name"] ?? null) === "Olivia" && $r["can_send"] === true, json_encode($r));
$pdo->exec("UPDATE admins SET active = 1 WHERE id = 3");

// ── 3b. Write to clients in Messages ───────────────────────────────────

$pdo->exec("INSERT INTO role_permissions (role, permission, allowed) VALUES ('super_admin', 'messages.clients', 1)");
[, $r] = amGet("action=client_list", $sa);
check("client messages: given the permission, a super admin has a Clients tab with their client", ($r["available"] ?? null) === true
    && array_column(array_column($r["chats"], "client"), "id") === [10, 13] && $r["chats"][0]["access"] === "team", json_encode($r));
[$status, $r] = amGet("action=client_thread&client=10", $sa);
check("client messages: and reads it", $status === 200 && $r["access"] === "team" && count($r["messages"]) === 1, "HTTP $status");
[$status, $r] = amPost($sa, ["action" => "client_send", "client" => 10, "body" => "Sam here, covering for Amy."]);
check("client messages: and writes in it", $status === 200 && $r["message_row"]["side"] === "team", "HTTP $status " . json_encode($r));
$pdo->exec("DELETE FROM client_messages WHERE id = " . (int) ($r["message_row"]["id"] ?? 0));
[, $r] = cmGet("action=thread", $alice);
check("client messages: Alice sees Sam among her account team", array_column($r["managers"], "name") === ["Amy", "Sam"], json_encode($r));
[$status] = amGet("action=client_thread&client=11", $sa);
check("client messages: a client they aren't assigned to is still not found", $status === 404, "HTTP $status");
[$status] = amGet("action=client_thread&client=10", $seo);
check("client messages: an admin without it, on the same client, is still not found", $status === 404, "HTTP $status");

$pdo->exec("INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (5, 'messages.clients', 1)");
[$status] = amGet("action=client_thread&client=10", $seo);
check("client messages: given to one admin, it opens the chat for them", $status === 200, "HTTP $status");
$pdo->exec("DELETE FROM admin_permission_overrides WHERE admin_id = 5 AND permission = 'messages.clients'");

$pdo->exec("INSERT INTO role_permissions (role, permission, allowed) VALUES ('account_manager', 'messages.clients', 0)");
[$status] = amGet("action=client_thread&client=10", $am);
check("client messages: taken from Account Managers, Amy loses the chat", $status === 404, "HTTP $status");
[, $r] = amGet("action=client_list", $am);
check("client messages: and the Clients tab", ($r["available"] ?? null) === false && $r["chats"] === [], json_encode($r));
[, $r] = cmGet("action=thread", "tok-bob");
check("client messages: Beta, with only Andy, falls to the Director", $r["managers"] === [] && ($r["director"]["name"] ?? null) === "Olivia", json_encode($r));
$pdo->exec("DELETE FROM role_permissions WHERE permission = 'messages.clients'");
[$status] = amGet("action=client_thread&client=10", $am);
check("client messages: back to the default, Amy has it again", $status === 200, "HTTP $status");

// ── 4. Unread, Seen, the badge ─────────────────────────────────────────

[, $r] = amGet("action=count", $am);
check("client messages: the staff badge counts client chats", ($r["client_unread"] ?? null) === 1
    && $r["unread"] >= 1 && ($r["client_latest_id"] ?? 0) === $aliceMessage, json_encode($r));

[$status, $r] = amPost($am, ["action" => "client_send", "client" => 10, "body" => "Sure - Friday works. https://example.test/plan"]);
check("client messages: Amy answers", $status === 200 && $r["message_row"]["side"] === "team" && $r["message_row"]["mine"] === true,
    "HTTP $status " . json_encode($r));
$amyMessage = $r["message_row"]["id"] ?? 0;

[, $r] = amGet("action=count", $am);
check("client messages: answering reads what came before", ($r["client_unread"] ?? null) === 0, json_encode($r));

[, $r] = cmGet("action=count", $alice);
check("client messages: Alice has one unread", ($r["unread"] ?? null) === 1 && ($r["latest_id"] ?? 0) === $amyMessage, json_encode($r));
[, $r] = cmGet("action=count", $tia);
check("client messages: so does Tia", ($r["unread"] ?? null) === 1, json_encode($r));

[$status, $r] = cmPost($alice, ["action" => "read"]);
check("client messages: Alice reads it", $status === 200 && $r["unread"] === 0, "HTTP $status " . json_encode($r));
[, $r] = cmGet("action=count", $tia);
check("client messages: reading is per person", ($r["unread"] ?? null) === 1, json_encode($r));

[, $r] = amGet("action=client_thread&client=10", $am);
$seen = array_column($r["seen_by"], "last_read_id", "name");
check("client messages: Amy sees who has read her message", ($seen["Alice"] ?? 0) >= $amyMessage && ($seen["Tia"] ?? 0) === 0, json_encode($r["seen_by"]));
[, $r] = cmGet("action=thread", $alice);
check("client messages: Alice sees Amy has read hers", ($r["seen_by"][0]["last_read_id"] ?? 0) >= $aliceMessage, json_encode($r["seen_by"] ?? null));

[, $r] = cmGet("action=thread&after=" . $aliceMessage, $tia);
check("client messages: after= returns only newer ones", array_column($r["messages"], "id") === [$amyMessage], json_encode($r));

// ── 4b. Edit and delete ────────────────────────────────────────────────

[, $r] = cmGet("action=thread", $tia);
$since = $r["clock"];
[, $r] = cmPost($alice, ["action" => "send", "body" => "Can we mvoe it?"]);
$typo = $r["message_row"]["id"] ?? 0;
[$status, $r] = cmPost($tia, ["action" => "edit", "id" => $typo, "body" => "Hacked"]);
check("client messages: a teammate can't edit Alice's message", $status === 403, "HTTP $status " . json_encode($r));
[$status] = amPost($am, ["action" => "client_edit", "client" => 10, "id" => $typo, "body" => "Hacked"]);
check("client messages: nor can her account manager", $status === 403, "HTTP $status");
[$status] = amPost($owner, ["action" => "client_delete", "client" => 10, "id" => $typo]);
check("client messages: nor the Director reading it", $status === 403, "HTTP $status");
[$status] = cmPost("tok-bob", ["action" => "delete", "id" => $typo]);
check("client messages: another client doesn't find it", $status === 404, "HTTP $status");
[$status, $r] = cmPost($alice, ["action" => "edit", "id" => $typo, "body" => "Can we move it?"]);
check("client messages: Alice fixes her typo", $status === 200 && $r["message_row"]["body"] === "Can we move it?"
    && $r["message_row"]["edited"] === true && $r["message_row"]["editable_for"] > 0, "HTTP $status " . json_encode($r));
[, $r] = cmGet("action=thread&after=$typo&since=$since", $tia);
check("client messages: Tia's next poll brings the edit", array_column($r["changed"], "id") === [$typo]
    && $r["changed"][0]["body"] === "Can we move it?" && $r["changed"][0]["edited"] === true, json_encode($r));

[, $before] = amGet("action=count", $am);
$since = $r["clock"];
[$status, $r] = cmPost($alice, ["action" => "delete", "id" => $typo]);
check("client messages: Alice deletes it", $status === 200 && $r["message_row"]["deleted"] === true && $r["message_row"]["body"] === "",
    "HTTP $status " . json_encode($r));
[, $r] = amGet("action=client_thread&client=10&after=" . ($typo - 1) . "&since=$since", $am);
check("client messages: Amy sees \"Message deleted\", never the words", ($r["messages"][0]["deleted"] ?? null) === true
    && $r["messages"][0]["body"] === "" && strpos(json_encode($r), "move it") === false, json_encode($r));
[, $after] = amGet("action=count", $am);
check("client messages: and it no longer counts as unread", $after["client_unread"] === $before["client_unread"] - 1, json_encode([$before, $after]));
[, $r] = amGet("action=client_list", $am);
check("client messages: the chat list says it was deleted", ($r["chats"][0]["last"]["preview"] ?? "") === "Message deleted", json_encode($r));
[$status] = cmPost($alice, ["action" => "edit", "id" => $typo, "body" => "Back again"]);
check("client messages: a deleted message stays deleted", $status === 409, "HTTP $status");
[, $r] = get("client-messages.php?client=10&limit=1", $am);
check("v1 client messages: read_client_chat shows it deleted, body null", ($r["data"]["items"][0]["deleted"] ?? null) === true
    && $r["data"]["items"][0]["untrusted_content"]["body"] === null, json_encode($r));

[, $r] = amPost($am, ["action" => "client_send", "client" => 10, "body" => "Noted."]);
$late = $r["message_row"]["id"] ?? 0;
$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 6 MINUTE WHERE id = $late");
[$status, $r] = amPost($am, ["action" => "client_edit", "client" => 10, "id" => $late, "body" => "Noted!"]);
check("client messages: after 5 minutes Amy can't edit hers", $status === 409 && strpos($r["message"], "5 minutes") !== false, "HTTP $status " . json_encode($r));
[$status] = amPost($am, ["action" => "client_delete", "client" => 10, "id" => $late]);
check("client messages: nor delete it", $status === 409, "HTTP $status");

// Out of the sweeps below.
$pdo->exec("DELETE FROM client_messages WHERE id IN ($typo, $late)");

// ── 5. The email for a message left unread ─────────────────────────────

$sent = [];
$record = function (array $to, $subject, $html) use (&$sent) {
    $sent[] = ["to" => $to[0]["email"], "subject" => $subject, "html" => $html];
    return 1;
};

clientMessageEmailSweep($pdo, $record);
check("client messages: nothing is emailed before 15 minutes", $sent === [], json_encode($sent));

$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 20 MINUTE");
clientMessageEmailSweep($pdo, $record);
check("client messages: only Tia still has an unread message, and is emailed", array_column($sent, "to") === ["tia@acme.test"]
    && $sent[0]["subject"] === "New message from Amy" && strpos($sent[0]["html"], "messages.html") !== false
    && strpos($sent[0]["html"], "Friday works") !== false, json_encode($sent));

$sent = [];
cmPost($alice, ["action" => "send", "body" => "And the budget?"]);
$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 20 MINUTE");
clientMessageEmailSweep($pdo, $record);
check("client messages: once only, then Amy for Alice's unread message", array_column($sent, "to") === ["a@w.test"]
    && $sent[0]["subject"] === "New message from Acme Ltd" && strpos($sent[0]["html"], "admin-messages.html?client=10") !== false,
    json_encode($sent));

$sent = [];
clientMessageEmailSweep($pdo, $record);
check("client messages: a second sweep sends nothing", $sent === [], json_encode($sent));

cmPost($tia, ["action" => "read"]);
amPost($am, ["action" => "client_send", "client" => 10, "body" => "Budget is fine."]);
$pdo->exec("UPDATE client_messages SET created_at = NOW() - INTERVAL 20 MINUTE");
clientMessageEmailSweep($pdo, $record);
check("client messages: opened since, so emailed again about the new one", in_array("tia@acme.test", array_column($sent, "to"), true), json_encode($sent));

check("client messages: the sweep runs at most once a minute",
    (function () use ($pdo) {
        $pdo->exec("UPDATE client_message_jobs SET ran_at = NOW() - INTERVAL 2 MINUTE");
        $first = claimClientMessageSweep($pdo);
        return $first && !claimClientMessageSweep($pdo);
    })());
$pdo->exec("UPDATE client_message_jobs SET ran_at = NOW() + INTERVAL 1 DAY WHERE name = 'email_sweep'");
cmPost($alice, ["action" => "read"]);
cmPost($tia, ["action" => "read"]);
amPost($am, ["action" => "client_read", "client" => 10]);

// ── 6. Through api/v1 (the MCP) ────────────────────────────────────────

setSiteSetting($pdo, "mcp_clients_enabled", "1");

[$status, $r] = get("me/messages.php?limit=2", $alice);
check("v1 client messages: the client's chat, newest first", $status === 200 && count($r["data"]["items"]) === 2
    && $r["data"]["items"][0]["untrusted_content"]["body"] === "Budget is fine." && $r["data"]["items"][0]["side"] === "team"
    && $r["data"]["items"][0]["from"] === "Amy" && is_string($r["data"]["next_cursor"])
    && array_column($r["data"]["account_managers"], "name") === ["Amy"] && $r["data"]["can_send"] === true,
    "HTTP $status " . json_encode($r));
$fixtures["my_messages"] = $r["data"] ?? null;

[$status, $r] = post("api/v1/me/messages.php", $tia, ["read" => true]);
check("v1 client messages: marking read", $status === 200 && $r["data"]["marked"] === true && $r["data"]["unread_count"] === 0,
    "HTTP $status " . json_encode($r));
$fixtures["mark_my_messages_read"] = $r["data"] ?? null;

[$prep] = change("v1 client messages: send_my_message", $alice, "send_my_message", ["body" => "  Sent by Alice's assistant  "]);
check("v1 client messages: the summary names the account manager and the words", strpos($prep["data"]["summary"] ?? "", "Amy") !== false
    && strpos($prep["data"]["summary"], "\"Sent by Alice's assistant\"") !== false, json_encode($prep));
$last = one($pdo, "SELECT user_id, sender_kind, sender_id, body FROM client_messages ORDER BY id DESC LIMIT 1");
check("v1 client messages: it lands in Acme's chat as Alice", (int) $last["user_id"] === 10 && $last["sender_kind"] === "user"
    && (int) $last["sender_id"] === 10 && $last["body"] === "Sent by Alice's assistant", json_encode($last));
[, $prep] = prepare("tok-nora", "send_my_message", ["body" => "Hello"]);
check("v1 client messages: without an account manager it goes to the Director", strpos($prep["data"]["summary"] ?? "", "Olivia, the Director") !== false,
    json_encode($prep));
[$status, $r] = get("me/messages.php", "tok-nora");
check("v1 client messages: my_messages names the Director answering", $status === 200 && $r["data"]["account_managers"] === []
    && $r["data"]["answered_by_director"]["name"] === "Olivia" && $r["data"]["can_send"] === true, "HTTP $status " . json_encode($r));
refusedChange("v1 client messages: staff can't use the client's tool", $am, "send_my_message", ["body" => "Hello"], 404);
[$status] = get("me/messages.php", $am);
check("v1 client messages: staff are refused the client's view", $status === 403, "HTTP $status");

[$status, $r] = get("client-messages.php", $am);
$c = $r["data"]["items"][0] ?? [];
check("v1 client messages: Amy lists her client chats", $status === 200 && count($r["data"]["items"]) === 1
    && $c["client"]["company_name"] === "Acme Ltd" && $c["unread"] === 1 && $c["last_from"] === "Alice"
    && $c["untrusted_content"]["last_message"] === "Sent by Alice's assistant", "HTTP $status " . json_encode($r));
$fixtures["list_client_chats"] = $r["data"] ?? null;

[$status, $r] = get("client-messages.php?client=10&limit=3", $am);
check("v1 client messages: Amy reads Acme's chat", $status === 200 && count($r["data"]["items"]) === 3
    && $r["data"]["items"][0]["from"] === "Alice" && array_column($r["data"]["people"], "name") === ["Alice", "Tia"],
    "HTTP $status " . json_encode($r));
$fixtures["read_client_chat"] = $r["data"] ?? null;

$v1Before = $chatReads();
[$status, $r] = get("client-messages.php?client=10&limit=2", $owner);
check("v1 client messages: the Director reads Acme's chat, read only", $status === 200 && ($r["data"]["access"] ?? null) === "observer"
    && count($r["data"]["items"]) === 2, "HTTP $status " . json_encode($r));
$readsNow = $chatReads();
get("client-messages.php?client=10&limit=2&cursor=" . urlencode($r["data"]["next_cursor"]), $owner);
check("v1 client messages: the Director's visit is logged, the next page isn't", $readsNow === $v1Before + 1 && $chatReads() === $readsNow);
[$status] = post("api/v1/client-messages.php", $owner, ["client" => 10]);
check("v1 client messages: the Director can't mark it read", $status === 403, "HTTP $status");
refusedChange("v1 client messages: the Director can't write in it", $owner, "send_client_message", ["client" => 10, "body" => "Hi"], 403);
[, $r] = get("client-messages.php", $owner);
check("v1 client messages: the Director lists every client", count($r["data"]["items"] ?? []) === count(allClientChatIds($pdo))
    && in_array("observer", array_column($r["data"]["items"], "access"), true), json_encode($r));

foreach (["a super admin on the team" => $sa, "another account manager" => $am2] as $who => $token) {
    [$status] = get("client-messages.php?client=10", $token);
    check("v1 client messages: $who can't read it", $status === 404, "HTTP $status");
    refusedChange("v1 client messages: $who can't write in it", $token, "send_client_message", ["client" => 10, "body" => "Hi"], 404);
}
[, $r] = get("client-messages.php", $sa);
check("v1 client messages: a super admin's list is empty", ($r["data"]["items"] ?? null) === [], json_encode($r));
[$status] = get("client-messages.php", $alice);
check("v1 client messages: a client is refused the staff view", $status === 403, "HTTP $status");

[$prep, $done] = change("v1 client messages: send_client_message", $am, "send_client_message", ["client" => 10, "body" => "Got it, thanks"]);
check("v1 client messages: the summary names the client", strpos($prep["data"]["summary"] ?? "", "Acme Ltd") !== false, json_encode($prep));
$last = one($pdo, "SELECT sender_kind, sender_id, body FROM client_messages ORDER BY id DESC LIMIT 1");
check("v1 client messages: it lands as Amy", $last["sender_kind"] === "admin" && (int) $last["sender_id"] === 3
    && $last["body"] === "Got it, thanks" && ($done["data"]["result"]["message_id"] ?? 0) > 0, json_encode($last));
refusedChange("v1 client messages: not empty", $am, "send_client_message", ["client" => 10, "body" => "   "], 422);

[$status, $r] = post("api/v1/client-messages.php", $am, ["client" => 10]);
check("v1 client messages: Amy marks it read", $status === 200 && $r["data"]["marked"] === true && $r["data"]["unread_count"] === 0,
    "HTTP $status " . json_encode($r));
$fixtures["mark_client_chat_read"] = $r["data"] ?? null;
[$status] = post("api/v1/client-messages.php", $am2, ["client" => 10]);
check("v1 client messages: marking read needs the chat to be yours", $status === 404, "HTTP $status");

// ── 7. Private ─────────────────────────────────────────────────────────

check("client messages: Tracking's Changes records nothing about them",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_events WHERE summary LIKE '%launch%' OR summary LIKE '%Budget%' OR action LIKE 'message.%'")["n"] === 0);
check("client messages: nothing reaches the notifications inbox",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE body LIKE '%launch%' OR title LIKE '%launch%' OR body LIKE '%Budget%'")["n"] === 0);

// Leave the other modules the seed they expect.
$pdo->exec("DELETE FROM users WHERE id IN (13, 30)");
$pdo->exec("DELETE FROM admin_user_assignments WHERE user_id = 13");
$pdo->exec("UPDATE users SET session_token = NULL WHERE id = 11");
$pdo->exec("DELETE FROM client_messages");
$pdo->exec("DELETE FROM client_message_reads");
setSiteSetting($pdo, "mcp_clients_enabled", "0");
