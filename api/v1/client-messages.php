<?php

/*
| An account manager's chats with their clients - the Clients tab of the
| Messages page.
|
|   GET  client-messages.php?cursor=&limit=              list_client_chats
|   GET  client-messages.php?client=10&cursor=&limit=    read_client_chat (newest first)
|   POST client-messages.php {"client": 10}              mark_client_chat_read
|
| Sending goes through prepare/confirm (send_client_message in _writes.php).
|
| Staff, scope messages:read (messages:write to mark read). An admin with the
| Account Manager role reaches a client's chat for the clients they are on the
| team of today; any other client - and any other role - is a 404. The
| Director lists and reads every client's chat (access "observer") but can't
| send or mark read (403), and each read_client_chat without a cursor is
| logged in Tracking, as opening the chat on the page is. The rules are
| client-message-writes.php's.
| Message bodies were typed by people: they come back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../client-message-writes.php";

$p = v1Principal($pdo);
v1RequireStaff($p);

// DDL commits implicitly, so before anything else.
ensureAdminColumns($pdo);
ensureClientMessageTables($pdo);

/** The client's chat, opened as one of mine or answered 404. */
function v1ClientChat(PDO $pdo, array $p, $id)
{
    try {
        return openClientChat($pdo, $p, $id);
    } catch (PortalWriteError $e) {
        v1Error(404, "not_found", $e->getMessage());
    }
}

function v1ClientChatClient(array $a)
{
    return [
        "id" => (int) $a["id"],
        "name" => (string) $a["name"],
        "company_name" => v1NullableString($a["company_name"] ?? null),
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    v1RequireScope($p, "messages:write");
    $in = json_decode(file_get_contents("php://input"), true);
    if (!is_array($in) || !is_int($in["client"] ?? null) || $in["client"] <= 0) {
        v1Error(422, "invalid_parameter", "client must be a client's id.");
    }
    $account = v1ClientChat($pdo, $p, $in["client"]);
    try {
        markClientChatRead($pdo, $p, $account);
    } catch (PortalWriteError $e) {
        v1Error($e->status, "forbidden", $e->getMessage());
    }
    v1Reply(200, ["marked" => true, "unread_count" => clientMessageUnreadCount($pdo, $p)]);
}

v1RequireMethod("GET");
v1RequireScope($p, "messages:read");

$limit = v1Limit();
$clientId = v1IntParam("client");

if ($clientId !== null) {
    $account = v1ClientChat($pdo, $p, $clientId);
    $cursor = v1IdCursor();
    if ($cursor === null) {
        logClientChatVisit($pdo, $account, "AI connection");
    }
    $page = v1Page(clientChatNewestFirst($pdo, $account["id"], $cursor, $limit), $limit, function ($m) {
        return [(int) $m["id"]];
    }, function ($m) use ($p) {
        return clientMessageV1Item($m, $p);
    });
    $clientRead = max(array_merge([0], array_column(clientChatSeenBy($pdo, $p, $account["id"]), "last_read_id")));

    v1Reply(200, array_merge(["client" => v1ClientChatClient($account), "access" => $account["access"]], $page, [
        "people" => array_map(function ($person) {
            return ["id" => (int) $person["id"], "name" => (string) $person["name"]];
        }, clientChatPeople($pdo, $account["id"])),
        // The newest message anyone at the client has seen.
        "client_last_read_id" => (int) $clientRead,
    ]));
}

/*
| Every client I manage, the latest message first, then the quiet ones by
| name - a short list, so one page of up to $limit with a position cursor.
*/
$chats = clientChatsFor($pdo, $p);
$offset = v1IdCursor() ?? 0;
$slice = array_slice($chats, $offset, $limit + 1);

$page = [
    "items" => array_map(function ($c) {
        return [
            "client" => [
                "id" => $c["client"]["id"],
                "name" => $c["client"]["name"],
                "company_name" => v1NullableString($c["client"]["company_name"]),
            ],
            "access" => $c["access"],
            "unread" => $c["unread"],
            "last_message_at" => $c["last"] ? v1Time($c["last"]["created_ts"]) : null,
            "last_is_mine" => $c["last"] ? $c["last"]["mine"] : false,
            "last_from" => $c["last"]["sender"] ?? null,
            "untrusted_content" => ["last_message" => $c["last"]["preview"] ?? ""],
        ];
    }, array_slice($slice, 0, $limit)),
    "next_cursor" => count($slice) > $limit ? v1EncodeCursor([$offset + $limit]) : null,
    "unread_count" => clientMessageUnreadCount($pdo, $p),
];

v1Reply(200, $page);
