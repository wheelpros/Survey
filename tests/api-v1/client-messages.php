<?php

/*
| Client messages - required by run.php after messages, sharing its scope.
|
|   1. A client account's chat: everyone on the account and its account
|      managers read and write it; nobody else on the staff side does - not
|      the Director, not a super admin or admin on the same client.
|   2. No account manager yet: the client can't send.
|   3. The chat belongs to the account: a new account manager reads the
|      history, one taken off the team loses it.
|   4. Unread, Seen, and the staff badge counting client chats.
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
    "the Director" => $owner,
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
[, $r] = amGet("action=client_list", $owner);
check("client messages: nor does the Director", ($r["chats"] ?? null) === [], json_encode($r));

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

// ── 2. No account manager yet ───────────────────────────────────────────

[, $r] = cmGet("action=thread", "tok-nora");
check("client messages: without an account manager there is no one to write to", ($r["managers"] ?? null) === []
    && $r["can_send"] === false, json_encode($r));
[$status, $r] = cmPost("tok-nora", ["action" => "send", "body" => "Hello?"]);
check("client messages: and sending is refused", $status === 409 && strpos($r["message"], "hasn't been assigned") !== false,
    "HTTP $status " . json_encode($r));

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
check("client messages: a switched-off account manager leaves the chat", ($r["managers"] ?? null) === [] && $r["can_send"] === false, json_encode($r));
$pdo->exec("UPDATE admins SET active = 1 WHERE id = 3");

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
refusedChange("v1 client messages: without an account manager", "tok-nora", "send_my_message", ["body" => "Hello"], 409);
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

foreach (["the Director" => $owner, "a super admin on the team" => $sa, "another account manager" => $am2] as $who => $token) {
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
