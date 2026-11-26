<?php

/*
|--------------------------------------------------------------------------
| Client messages: a client account's chat with its account managers
|--------------------------------------------------------------------------
|
| Used by api/client-messages.php (the client's page), api/admin-messages.php
| (the Clients tab of the staff page) and api/v1 (the MCP), so a message is
| checked, rate limited and stored the same way from all three.
|
| One chat per client account. On the client's side, everyone on the account
| (the owner and the teammates they invited) reads and writes it. On the team's
| side, only the admins with the Account Manager role who are on the client's
| team today (admin_user_assignments). Nobody else reaches it - not the
| Director, not a super admin or admin on the same client, not another client.
| A chat that isn't the caller's is "not found", the same as a missing one.
|
| The chat belongs to the account, not to the people in it: a new account
| manager reads the whole history, one taken off the team loses it at once.
| A client with no account manager yet can read nothing new and can't write.
|
| Text only, like the staff chat. Nothing here writes to Tracking or to the
| notifications inbox. The one other way out is the email: someone with a
| message from the other side still unread after 15 minutes is emailed once,
| and not again until they have opened the chat (clientMessageEmailSweep()).
|
| The caller has run ensureAdminColumns() and ensureClientMessageTables()
| before any of this (DDL commits implicitly).
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/rate-limit.php";

const CLIENT_MESSAGE_MAX_CHARS = 2000;
const CLIENT_MESSAGE_PAGE_SIZE = 50;
const CLIENT_MESSAGE_EMAIL_AFTER = 900;      // seconds unread before an email
const CLIENT_MESSAGE_EMAIL_WINDOW_DAYS = 7;  // older messages never email
const CLIENT_MESSAGE_SWEEP_EVERY = 60;       // seconds between email sweeps

const CLIENT_CHAT_NOT_FOUND = "No client chat with that id among the clients you manage.";
const CLIENT_CHAT_NO_MANAGER = "Your account manager hasn't been assigned yet. You can send messages once they have.";

/*
|--------------------------------------------------------------------------
| Who is in a chat
|--------------------------------------------------------------------------
*/

/** The account's owner row while it is an approved account, or null. */
function clientChatAccount(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, name, email, company_name, profile_image
        FROM users
        WHERE id = ? AND approved = 1 AND account_owner_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([(int) $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The active account managers on the account's team, by name. */
function clientChatManagers(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.email, a.position, a.avatar_path
        FROM admin_user_assignments aua
        JOIN admins a ON a.id = aua.admin_id
        WHERE aua.user_id = ? AND a.role = 'account_manager' AND (a.active IS NULL OR a.active = 1)
        GROUP BY a.id, a.name, a.email, a.position, a.avatar_path
        ORDER BY a.name, a.id
    ");
    $stmt->execute([(int) $accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Everyone on the client's side who can sign in: the owner and teammates. */
function clientChatPeople(PDO $pdo, $accountId)
{
    return clientAccountPeople($pdo, $accountId);
}

/** The accounts this admin is an account manager of - none for any other role. */
function managedClientIds(PDO $pdo, array $admin)
{
    if (($admin["kind"] ?? "") !== "admin" || ($admin["role"] ?? "") !== "account_manager") {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id
        FROM admin_user_assignments aua
        JOIN users u ON u.id = aua.user_id AND u.approved = 1 AND u.account_owner_id IS NULL
        WHERE aua.admin_id = ?
    ");
    $stmt->execute([(int) $admin["id"]]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The chat $p may open: the account's row, plus "side" - "client" for
 * someone on the account, "team" for one of its account managers. A client
 * always gets their own account's chat, whatever $accountId says. Throws
 * PortalWriteError 404 for anything else.
 */
function openClientChat(PDO $pdo, array $p, $accountId = null)
{
    if ($p["kind"] === "user") {
        $account = clientChatAccount($pdo, $p["account_id"]);
        if (!$account) {
            throw new PortalWriteError("Your account can't use Messages right now.", 404);
        }
        return $account + ["side" => "client"];
    }

    $accountId = (int) $accountId;
    if ($accountId <= 0 || !in_array($accountId, managedClientIds($pdo, $p), true)) {
        throw new PortalWriteError(CLIENT_CHAT_NOT_FOUND, 404);
    }
    return clientChatAccount($pdo, $accountId) + ["side" => "team"];
}

/** The sender kind of the other side: what counts as unread for $p. */
function clientChatOtherKind(array $p)
{
    return $p["kind"] === "user" ? "admin" : "user";
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

/** The SELECT every message read shares: the row plus the sender's name today. */
function clientMessageSelect()
{
    return "
        SELECT m.id, m.user_id, m.sender_kind, m.sender_id, m.sender_name, m.body, m.created_at,
               UNIX_TIMESTAMP(m.created_at) AS created_ts,
               COALESCE(a.name, u.name) AS live_name
        FROM client_messages m
        LEFT JOIN admins a ON m.sender_kind = 'admin' AND a.id = m.sender_id
        LEFT JOIN users u ON m.sender_kind = 'user' AND u.id = m.sender_id
    ";
}

/** One message as the pages show it. */
function clientMessageRow(array $m, array $p)
{
    $side = $m["sender_kind"] === "user" ? "client" : "team";
    $name = (string) ($m["live_name"] ?? "") !== "" ? (string) $m["live_name"] : (string) $m["sender_name"];

    return [
        "id"         => (int) $m["id"],
        "side"       => $side,
        "mine"       => $m["sender_kind"] === ($p["kind"] === "user" ? "user" : "admin") && (int) $m["sender_id"] === (int) $p["id"],
        "sender"     => ["id" => (int) $m["sender_id"], "name" => $name !== "" ? $name : ($side === "team" ? "Account manager" : "Teammate")],
        "body"       => (string) $m["body"],
        "created_at" => (string) $m["created_at"],
        "created_ts" => (int) $m["created_ts"],
    ];
}

/**
 * A page of the chat, oldest first: [$rows, $hasOlder]. $after > 0 is
 * polling (everything newer, $hasOlder null); otherwise the latest page, or
 * the page before $before.
 */
function clientChatMessages(PDO $pdo, $accountId, $after = 0, $before = 0, $limit = CLIENT_MESSAGE_PAGE_SIZE)
{
    $accountId = (int) $accountId;

    if ($after > 0) {
        $stmt = $pdo->prepare(clientMessageSelect() . " WHERE m.user_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200");
        $stmt->execute([$accountId, (int) $after]);
        return [$stmt->fetchAll(PDO::FETCH_ASSOC), null];
    }

    $stmt = $pdo->prepare(
        clientMessageSelect() . " WHERE m.user_id = ?" . ($before > 0 ? " AND m.id < ?" : "")
        . " ORDER BY m.id DESC LIMIT " . ((int) $limit + 1)
    );
    $stmt->execute($before > 0 ? [$accountId, (int) $before] : [$accountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [array_reverse(array_slice($rows, 0, (int) $limit)), count($rows) > $limit];
}

/** Newest first, up to $limit + 1 rows older than $beforeId (null: from the latest) - api/v1's paging. */
function clientChatNewestFirst(PDO $pdo, $accountId, $beforeId, $limit)
{
    $stmt = $pdo->prepare(
        clientMessageSelect() . " WHERE m.user_id = ?" . ($beforeId !== null ? " AND m.id < ?" : "")
        . " ORDER BY m.id DESC LIMIT " . ((int) $limit + 1)
    );
    $stmt->execute($beforeId !== null ? [(int) $accountId, (int) $beforeId] : [(int) $accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A message as api/v1 returns it: what people typed under untrusted_content. */
function clientMessageV1Item(array $m, array $p)
{
    $row = clientMessageRow($m, $p);
    return [
        "id" => $row["id"],
        "side" => $row["side"],
        "mine" => $row["mine"],
        "from" => $row["sender"]["name"],
        "created_at" => gmdate("Y-m-d\\TH:i:s\\Z", $row["created_ts"]),
        "untrusted_content" => ["body" => $row["body"]],
    ];
}

/** One message by id, with its sender's name. */
function clientMessageById(PDO $pdo, $id)
{
    $stmt = $pdo->prepare(clientMessageSelect() . " WHERE m.id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Who on the other side of $p has read how far: [{name, last_read_id}], for
 * the people in the chat today. The page marks its side's last message "Seen".
 */
function clientChatSeenBy(PDO $pdo, array $p, $accountId)
{
    $people = $p["kind"] === "user" ? clientChatManagers($pdo, $accountId) : clientChatPeople($pdo, $accountId);
    $kind = clientChatOtherKind($p);

    $stmt = $pdo->prepare("SELECT reader_id, last_read_id FROM client_message_reads WHERE user_id = ? AND reader_kind = ?");
    $stmt->execute([(int) $accountId, $kind]);
    $reads = array_map("intval", $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    return array_map(function ($person) use ($reads) {
        return ["name" => (string) $person["name"], "last_read_id" => $reads[(int) $person["id"]] ?? 0];
    }, $people);
}

/** How many messages from the other side $p hasn't read, in the given chats. */
function clientUnreadIn(PDO $pdo, array $p, array $accountIds)
{
    if (!$accountIds) {
        return [];
    }
    $marks = implode(",", array_fill(0, count($accountIds), "?"));
    $stmt = $pdo->prepare("
        SELECT m.user_id, COUNT(*) AS n
        FROM client_messages m
        LEFT JOIN client_message_reads r ON r.user_id = m.user_id AND r.reader_kind = ? AND r.reader_id = ?
        WHERE m.user_id IN ($marks) AND m.sender_kind = ? AND m.id > COALESCE(r.last_read_id, 0)
        GROUP BY m.user_id
    ");
    $stmt->execute(array_merge([$p["kind"], (int) $p["id"]], array_map("intval", $accountIds), [clientChatOtherKind($p)]));
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** The chats $p is in: the client's one account, or the accounts they manage. */
function clientChatIdsFor(PDO $pdo, array $p)
{
    if ($p["kind"] === "user") {
        return clientChatAccount($pdo, $p["account_id"]) ? [(int) $p["account_id"]] : [];
    }
    return managedClientIds($pdo, $p);
}

/** $p's unread total across their client chats. */
function clientMessageUnreadCount(PDO $pdo, array $p)
{
    return array_sum(clientUnreadIn($pdo, $p, clientChatIdsFor($pdo, $p)));
}

/** The newest message from the other side in any of $p's chats, read or not - for the new message sound. */
function clientMessageLatestIncoming(PDO $pdo, array $p)
{
    $ids = clientChatIdsFor($pdo, $p);
    if (!$ids) {
        return 0;
    }
    $marks = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM client_messages WHERE user_id IN ($marks) AND sender_kind = ?");
    $stmt->execute(array_merge($ids, [clientChatOtherKind($p)]));
    return (int) $stmt->fetchColumn();
}

/**
 * An account manager's client chats: every client they manage, the latest
 * message first, then the quiet ones by name.
 */
function clientChatsFor(PDO $pdo, array $admin)
{
    $ids = managedClientIds($pdo, $admin);
    if (!$ids) {
        return [];
    }

    $marks = implode(",", array_fill(0, count($ids), "?"));
    $accounts = $pdo->prepare("SELECT id, name, company_name, profile_image FROM users WHERE id IN ($marks)");
    $accounts->execute($ids);

    $latest = $pdo->prepare(clientMessageSelect() . "
        WHERE m.id IN (SELECT MAX(id) FROM client_messages WHERE user_id IN ($marks) GROUP BY user_id)
    ");
    $latest->execute($ids);
    $lastByAccount = [];
    foreach ($latest->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $lastByAccount[(int) $m["user_id"]] = $m;
    }

    $unread = clientUnreadIn($pdo, $admin, $ids);

    $chats = array_map(function ($a) use ($admin, $lastByAccount, $unread) {
        $id = (int) $a["id"];
        $last = $lastByAccount[$id] ?? null;
        return [
            "client" => clientChatPerson($a),
            "unread" => $unread[$id] ?? 0,
            "last"   => $last ? clientChatPreview($last, $admin) : null,
        ];
    }, $accounts->fetchAll(PDO::FETCH_ASSOC));

    usort($chats, function ($x, $y) {
        $byLast = ($y["last"]["id"] ?? 0) <=> ($x["last"]["id"] ?? 0);
        return $byLast !== 0 ? $byLast : strcasecmp($x["client"]["company_name"] ?: $x["client"]["name"], $y["client"]["company_name"] ?: $y["client"]["name"]);
    });

    return $chats;
}

/** A client account as the chat list shows it. */
function clientChatPerson(array $a)
{
    return [
        "id"           => (int) $a["id"],
        "name"         => (string) $a["name"],
        "company_name" => (string) ($a["company_name"] ?? ""),
        "avatar_path"  => ($a["profile_image"] ?? "") ?: null,
    ];
}

/** The last message of a chat, cut for the list. */
function clientChatPreview(array $m, array $p)
{
    $row = clientMessageRow($m, $p);
    $body = $row["body"];
    return [
        "id"         => $row["id"],
        "mine"       => $row["mine"],
        "side"       => $row["side"],
        "sender"     => $row["sender"]["name"],
        "preview"    => mb_strlen($body) > 120 ? mb_substr($body, 0, 119) . "…" : $body,
        "created_ts" => $row["created_ts"],
    ];
}

/** An account manager as the client's page shows them. */
function clientChatManagerRow(array $a)
{
    return [
        "id"          => (int) $a["id"],
        "name"        => (string) $a["name"],
        "position"    => (string) ($a["position"] ?? ""),
        "avatar_path" => ($a["avatar_path"] ?? "") ?: null,
    ];
}

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

/** The message $p is about to send to $account's chat, checked. Throws PortalWriteError. */
function checkClientMessage(PDO $pdo, array $p, array $account, $rawBody)
{
    $body = trim(str_replace("\r\n", "\n", (string) $rawBody));
    if ($body === "") {
        throw new PortalWriteError("Write a message first.", 400);
    }
    if (mb_strlen($body) > CLIENT_MESSAGE_MAX_CHARS) {
        throw new PortalWriteError("A message can be up to " . CLIENT_MESSAGE_MAX_CHARS . " characters.", 400);
    }
    if ($p["kind"] === "user" && !clientChatManagers($pdo, $account["id"])) {
        throw new PortalWriteError(CLIENT_CHAT_NO_MANAGER, 409);
    }
    return $body;
}

/** Sends one message from $p into $account's chat and returns the stored row. */
function sendClientMessage(PDO $pdo, array $p, array $account, $rawBody)
{
    $body = checkClientMessage($pdo, $p, $account, $rawBody);

    if (rateLimited($pdo, "client-message:" . $p["kind"] . ":" . (int) $p["id"], 30, 30, 60)) {
        throw new PortalWriteError("You're sending messages too quickly. Wait a minute and try again.", 429);
    }

    try {
        $pdo->prepare("INSERT INTO client_messages (user_id, sender_kind, sender_id, sender_name, body) VALUES (?, ?, ?, ?, ?)")
            ->execute([(int) $account["id"], $p["kind"], (int) $p["id"], mb_substr((string) $p["name"], 0, 190), $body]);
        $messageId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        throw new PortalWriteError("Could not send the message.", 500);
    }

    // Sending means I've read everything up to my own message.
    moveClientReadMarker($pdo, $p, $account["id"], $messageId);

    return clientMessageById($pdo, $messageId);
}

/** Marks $p's side of the chat read up to its latest message. Returns the new marker. */
function markClientChatRead(PDO $pdo, array $p, $accountId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM client_messages WHERE user_id = ?");
    $stmt->execute([(int) $accountId]);
    $latest = (int) $stmt->fetchColumn();
    moveClientReadMarker($pdo, $p, $accountId, $latest);
    return $latest;
}

/** Moves $p's read marker forward to $messageId, never back. */
function moveClientReadMarker(PDO $pdo, array $p, $accountId, $messageId)
{
    $pdo->prepare("
        INSERT INTO client_message_reads (user_id, reader_kind, reader_id, last_read_id) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))
    ")->execute([(int) $accountId, $p["kind"], (int) $p["id"], (int) $messageId]);
}

/*
|--------------------------------------------------------------------------
| The email for a message left unread
|--------------------------------------------------------------------------
|
| The host has no background jobs, so the unread-count checks every open
| portal page makes run this, at most once a minute (runClientMessageSweep()).
|
| Each person in a chat - the client's people, the account managers - is
| emailed when a message from the other side has been unread for 15 minutes.
| emailed_up_to_id then records the newest message at the time, and while it
| is past their read marker nothing more is sent: one email, then quiet until
| they open the chat.
*/

/**
 * Emails everyone due one. $send(recipients, subject, html) does the sending
 * (sendPortalEmail by default) and returns how many went. Returns the number
 * of people emailed.
 */
function clientMessageEmailSweep(PDO $pdo, ?callable $send = null)
{
    if ($send === null) {
        require_once __DIR__ . "/mailer.php";
        $send = function (array $recipients, $subject, $html) {
            return sendPortalEmail($recipients, $subject, $html);
        };
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT user_id FROM client_messages
        WHERE created_at >= NOW() - INTERVAL " . CLIENT_MESSAGE_EMAIL_WINDOW_DAYS . " DAY
          AND created_at <= NOW() - INTERVAL " . CLIENT_MESSAGE_EMAIL_AFTER . " SECOND
    ");
    $stmt->execute();

    $emailed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
        $account = clientChatAccount($pdo, $accountId);
        if (!$account) {
            continue;
        }
        foreach (clientChatPeople($pdo, $accountId) as $person) {
            $emailed += emailClientChatReader($pdo, $account, "user", $person, $send);
        }
        foreach (clientChatManagers($pdo, $accountId) as $manager) {
            $emailed += emailClientChatReader($pdo, $account, "admin", $manager, $send);
        }
    }
    return $emailed;
}

/** One person's email, if one is due. 1 when it was sent, else 0. */
function emailClientChatReader(PDO $pdo, array $account, $kind, array $person, callable $send)
{
    $accountId = (int) $account["id"];
    $otherKind = $kind === "user" ? "admin" : "user";

    $stmt = $pdo->prepare("SELECT last_read_id, emailed_up_to_id FROM client_message_reads WHERE user_id = ? AND reader_kind = ? AND reader_id = ?");
    $stmt->execute([$accountId, $kind, (int) $person["id"]]);
    $read = $stmt->fetch(PDO::FETCH_ASSOC) ?: ["last_read_id" => 0, "emailed_up_to_id" => 0];
    $lastRead = (int) $read["last_read_id"];

    // Emailed already, and the chat not opened since.
    if ((int) $read["emailed_up_to_id"] > $lastRead) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS unread, MAX(id) AS newest,
               SUM(created_at <= NOW() - INTERVAL " . CLIENT_MESSAGE_EMAIL_AFTER . " SECOND) AS due
        FROM client_messages
        WHERE user_id = ? AND sender_kind = ? AND id > ?
    ");
    $stmt->execute([$accountId, $otherKind, $lastRead]);
    $unread = $stmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $unread["due"] === 0) {
        return 0;
    }

    // Marked before sending: a mail server that is down costs this one email,
    // not an attempt every minute.
    $pdo->prepare("
        INSERT INTO client_message_reads (user_id, reader_kind, reader_id, emailed_up_to_id) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE emailed_up_to_id = VALUES(emailed_up_to_id)
    ")->execute([$accountId, $kind, (int) $person["id"], (int) $unread["newest"]]);

    $newest = clientMessageById($pdo, $unread["newest"]);
    [$subject, $html] = clientChatEmail($account, $kind, $newest, (int) $unread["unread"]);

    return $send([["email" => $person["email"], "name" => $person["name"]]], $subject, $html) > 0 ? 1 : 0;
}

/** [subject, html] of the email to someone on $kind's side about $newest. */
function clientChatEmail(array $account, $kind, array $newest, $unread)
{
    if (!function_exists("adminEmailHtml")) {
        require_once __DIR__ . "/mailer.php";
    }
    $from = clientMessageRow($newest, ["kind" => "", "id" => 0])["sender"]["name"];
    $body = (string) $newest["body"];
    $preview = mb_strlen($body) > 300 ? mb_substr($body, 0, 299) . "…" : $body;
    $more = $unread > 1 ? $unread . " unread messages" : null;

    if ($kind === "user") {
        return [
            "New message from " . $from,
            adminEmailHtml("You have a new message", $from . " from your account team wrote to you in the portal.",
                ["From" => $from, "Message" => $preview, "Waiting" => $more], "messages.html", "Open Messages"),
        ];
    }

    $client = trim((string) $account["company_name"]) !== "" ? (string) $account["company_name"] : (string) $account["name"];
    return [
        "New message from " . $client,
        adminEmailHtml("A client wrote to you", $from . " at " . $client . " sent a message in the portal.",
            ["Client" => $client, "From" => $from, "Message" => $preview, "Waiting" => $more],
            "admin-messages.html?client=" . (int) $account["id"], "Open the chat"),
    ];
}

/** True when the sweep is due, and claims it so no other request runs it too. */
function claimClientMessageSweep(PDO $pdo)
{
    $stmt = $pdo->prepare("
        UPDATE client_message_jobs SET ran_at = NOW()
        WHERE name = 'email_sweep' AND ran_at <= NOW() - INTERVAL " . CLIENT_MESSAGE_SWEEP_EVERY . " SECOND
    ");
    $stmt->execute();
    return $stmt->rowCount() === 1;
}

/**
 * Runs the email sweep once the reply has gone out, if a minute has passed
 * since the last one. Never fails the request that triggered it.
 */
function runClientMessageSweep(PDO $pdo)
{
    register_shutdown_function(function () use ($pdo) {
        if (function_exists("fastcgi_finish_request")) {
            fastcgi_finish_request();
        }
        try {
            if (claimClientMessageSweep($pdo)) {
                clientMessageEmailSweep($pdo);
            }
        } catch (Throwable $e) {
            error_log("client message email sweep failed: " . $e->getMessage());
        }
    });
}
