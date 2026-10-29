<?php

/*
| Messages between admins - required by run.php last, sharing its scope.
|
|   1. Sending: one conversation per pair, whoever writes first; the body
|      and the recipient are checked.
|   2. Privacy: only the two people in a chat read it - not the Director,
|      not another admin, not a client, not an AI token.
|   3. Unread counts, the read marker, and paging (after= for polling,
|      before= for older messages).
*/

function msgGet($query, $token)
{
    return pageGet("api/admin-messages.php?" . $query, $token);
}

function msgPost($token, array $body)
{
    return post("api/admin-messages.php", $token, $body);
}

// ── 1. Sending ───────────────────────────────────────────────────────────

[$status, $r] = msgPost($sa, ["action" => "send", "to" => 3, "body" => "  Hi Amy, the Acme copy is ready: https://example.test/doc  "]);
check("messages: Sam writes to Amy", $status === 200 && ($r["message_row"]["mine"] ?? null) === true
    && $r["message_row"]["body"] === "Hi Amy, the Acme copy is ready: https://example.test/doc", "HTTP $status " . json_encode($r));

[$status, $r] = msgPost($am, ["action" => "send", "to" => 2, "body" => "Thanks, looking now"]);
check("messages: Amy answers in the same chat", $status === 200
    && (int) one($pdo, "SELECT COUNT(*) AS n FROM admin_conversations")["n"] === 1, "HTTP $status " . json_encode($r));

msgPost($sa, ["action" => "send", "to" => 3, "body" => "One more thing"]);
msgPost($sa, ["action" => "send", "to" => 3, "body" => "And another"]);

foreach ([
    "an empty message" => ["to" => 3, "body" => "   "],
    "a message over 2000 characters" => ["to" => 3, "body" => str_repeat("x", 2001)],
    "a message to yourself" => ["to" => 2, "body" => "me"],
] as $what => $args) {
    [$status] = msgPost($sa, ["action" => "send"] + $args);
    check("messages: $what is refused", $status === 400, "HTTP $status");
}
// $sa is admin 2; "to yourself" above used to = 2.
[$status, $r] = msgPost($sa, ["action" => "send", "to" => 6, "body" => "Hello Dee"]);
check("messages: a switched-off admin can't receive", $status === 400 && strpos($r["message"], "switched off") !== false, "HTTP $status " . json_encode($r));
[$status] = msgPost($sa, ["action" => "send", "to" => 9999, "body" => "Hello?"]);
check("messages: an unknown admin is not found", $status === 404, "HTTP $status");

// ── 2. Privacy ───────────────────────────────────────────────────────────

[$status, $r] = msgGet("action=thread&with=3", $sa);
check("messages: Sam reads the chat", $status === 200 && count($r["messages"]) === 4
    && $r["with"]["name"] === "Amy" && $r["messages"][0]["mine"] === true && $r["messages"][1]["mine"] === false,
    "HTTP $status " . json_encode($r));

// Olivia (the Director) and Andy are not in it: their own Sam or Amy threads are empty.
[, $r] = msgGet("action=list", $owner);
check("messages: the Director doesn't see other people's chats", ($r["conversations"] ?? null) === [], json_encode($r));
[, $r] = msgGet("action=thread&with=3", $owner);
check("messages: the Director's own thread with Amy is empty", ($r["messages"] ?? null) === [], json_encode($r));
[, $r] = msgGet("action=list", $am2);
check("messages: another admin sees nothing", ($r["conversations"] ?? null) === [], json_encode($r));

[$status] = msgGet("action=list", "tok-client");
check("messages: a client is refused", $status === 403, "HTTP $status");
[$status] = msgGet("action=list", $mcpToken);
check("messages: an AI token is refused", $status === 403, "HTTP $status");
[$status] = msgGet("action=list", "nobody");
check("messages: no session is refused", $status === 401, "HTTP $status");

check("messages: nothing reaches the notifications inbox",
    (int) one($pdo, "SELECT COUNT(*) AS n FROM notifications WHERE body LIKE '%Acme copy%' OR title LIKE '%Acme copy%'")["n"] === 0);

// ── 3. Unread, read, paging ──────────────────────────────────────────────

// Amy's reply counts as reading what came before it, so only Sam's two
// later messages are unread.
[, $r] = msgGet("action=count", $am);
check("messages: Amy has 2 unread", ($r["unread"] ?? null) === 2, json_encode($r));
[, $r] = msgGet("action=count", $sa);
check("messages: your own messages are never unread", ($r["unread"] ?? null) === 0, json_encode($r));

[, $r] = msgGet("action=list", $am);
$c = $r["conversations"][0] ?? [];
check("messages: the list shows the chat", count($r["conversations"]) === 1 && $c["with"]["name"] === "Sam"
    && $c["unread"] === 2 && $c["last"]["preview"] === "And another" && $c["last"]["mine"] === false
    && is_int($c["last"]["id"]) && $c["last"]["id"] > 0, json_encode($r));

[$status] = msgPost($am, ["action" => "read", "with" => 2]);
[, $r] = msgGet("action=count", $am);
check("messages: reading clears the count", $status === 200 && ($r["unread"] ?? null) === 0, json_encode($r));

[, $r] = msgGet("action=thread&with=3", $sa);
$ids = array_column($r["messages"], "id");
check("messages: Sam can see Amy has read up to the last", ($r["their_last_read_id"] ?? 0) === end($ids), json_encode($r));

[, $r] = msgGet("action=thread&with=3&after=" . $ids[1], $sa);
check("messages: after= returns only newer ones", array_column($r["messages"], "id") === array_slice($ids, 2), json_encode($r));

for ($i = 1; $i <= 55; $i++) {
    $pdo->prepare("INSERT INTO admin_messages (conversation_id, sender_id, body) SELECT id, 3, ? FROM admin_conversations LIMIT 1")
        ->execute(["Bulk $i"]);
}
$pdo->exec("UPDATE admin_conversations SET last_message_id = (SELECT MAX(id) FROM admin_messages)");
[, $r] = msgGet("action=thread&with=2", $am);
check("messages: the latest page is 50, with older ones to load", count($r["messages"]) === 50 && $r["has_older"] === true
    && end($r["messages"])["body"] === "Bulk 55", json_encode(array_slice($r["messages"], -1)));
[, $older] = msgGet("action=thread&with=2&before=" . $r["messages"][0]["id"], $am);
check("messages: before= pages back to the start", count($older["messages"]) === 9 && $older["has_older"] === false
    && $older["messages"][0]["body"] === "Hi Amy, the Acme copy is ready: https://example.test/doc", json_encode($older));

[, $r] = msgGet("action=people", $sa);
$names = array_column($r["people"] ?? [], "name");
check("messages: the people list is the active team but me", in_array("Amy", $names, true)
    && !in_array("Sam", $names, true) && !in_array("Dee", $names, true), json_encode($names));
