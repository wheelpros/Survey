<?php

/*
|--------------------------------------------------------------------------
| Messages between admins: the shared reads and writes
|--------------------------------------------------------------------------
|
| Used by api/admin-messages.php (the page) and api/v1 (the MCP), so a
| message is checked, rate limited and stored the same way from either.
|
| Private: every lookup is by the caller and one other admin, so a caller
| only ever reaches a conversation they are one of the two people in - the
| Director included. Sending writes one Tracking row - who messaged whom,
| and when - and never the text. Nothing here writes to the notifications
| inbox.
|
| The caller has run ensureAdminColumns() and ensureAdminMessageTables()
| before any of this (DDL commits implicitly).
|
*/

require_once __DIR__ . "/events.php";

const MESSAGE_MAX_CHARS = 2000;
const MESSAGE_PAGE_SIZE = 50;

/** The other admin as the pages show them; "removed" when the row is gone. */
function messageAdmin(PDO $pdo, $id)
{
    $stmt = $pdo->prepare("SELECT id, name, role, position, avatar_path, active FROM admins WHERE id = ?");
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ["id" => (int) $id, "name" => "Removed admin", "role" => "", "position" => "",
                "avatar_path" => null, "active" => false, "removed" => true];
    }

    return [
        "id"          => (int) $row["id"],
        "name"        => (string) $row["name"],
        "role"        => (string) $row["role"],
        "position"    => (string) ($row["position"] ?? ""),
        "avatar_path" => $row["avatar_path"] ?: null,
        "active"      => !isset($row["active"]) || (int) $row["active"] === 1,
        "removed"     => false,
    ];
}

/** The pair's conversation row, or null. Smaller id first, as stored. */
function findConversation(PDO $pdo, $a, $b)
{
    $stmt = $pdo->prepare("SELECT * FROM admin_conversations WHERE admin_low = ? AND admin_high = ?");
    $stmt->execute([min($a, $b), max($a, $b)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Which read-marker column is mine in this conversation. */
function myReadColumn(array $conversation, $me)
{
    return (int) $conversation["admin_low"] === (int) $me ? "low_last_read_id" : "high_last_read_id";
}

/** The other person's read marker: the last of my messages they have seen. */
function theirReadId(array $conversation, $me)
{
    return (int) $conversation[(int) $conversation["admin_low"] === (int) $me ? "high_last_read_id" : "low_last_read_id"];
}

/** How many messages sent to me I have not read, across every conversation. */
function messageUnreadCount(PDO $pdo, $me)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM admin_conversations c
        JOIN admin_messages m ON m.conversation_id = c.id
        WHERE (c.admin_low = :me1 AND m.id > c.low_last_read_id AND m.sender_id <> :me2)
           OR (c.admin_high = :me3 AND m.id > c.high_last_read_id AND m.sender_id <> :me4)
    ");
    $me = (int) $me;
    $stmt->execute(["me1" => $me, "me2" => $me, "me3" => $me, "me4" => $me]);
    return (int) $stmt->fetchColumn();
}

/** Active admins other than me, by name - who I can start a chat with. */
function messagePeople(PDO $pdo, $me)
{
    $stmt = $pdo->prepare("
        SELECT id, name, role, position, avatar_path
        FROM admins
        WHERE id <> ? AND (active IS NULL OR active = 1)
        ORDER BY name
    ");
    $stmt->execute([(int) $me]);
    return array_map(function ($a) {
        return [
            "id" => (int) $a["id"],
            "name" => (string) $a["name"],
            "role" => (string) $a["role"],
            "position" => (string) ($a["position"] ?? ""),
            "avatar_path" => $a["avatar_path"] ?: null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * A message about to be sent, checked: [$body, $other]. Throws
 * PortalWriteError with the status the page answers.
 */
function checkMessage(PDO $pdo, $me, $otherId, $rawBody)
{
    $otherId = (int) $otherId;
    if ($otherId <= 0 || $otherId === (int) $me) {
        throw new PortalWriteError("Pick someone to message.", 400);
    }

    $body = trim(str_replace("\r\n", "\n", (string) $rawBody));
    if ($body === "") {
        throw new PortalWriteError("Write a message first.", 400);
    }
    if (mb_strlen($body) > MESSAGE_MAX_CHARS) {
        throw new PortalWriteError("A message can be up to " . MESSAGE_MAX_CHARS . " characters.", 400);
    }

    $other = messageAdmin($pdo, $otherId);
    if ($other["removed"]) {
        throw new PortalWriteError("That person isn't on the team.", 404);
    }
    if (!$other["active"]) {
        throw new PortalWriteError($other["name"] . "'s account is switched off, so they can't receive messages.", 400);
    }

    return [$body, $other];
}

/**
 * Sends one message from $me to $otherId and returns the stored row
 * (with created_ts). Checked by checkMessage() first; throws
 * PortalWriteError.
 */
function sendMessage(PDO $pdo, $me, $otherId, $rawBody)
{
    $me = (int) $me;
    [$body, $other] = checkMessage($pdo, $me, $otherId, $rawBody);
    $otherId = (int) $otherId;

    require_once __DIR__ . "/rate-limit.php";
    if (rateLimited($pdo, "admin-message:" . $me, 30, 30, 60)) {
        throw new PortalWriteError("You're sending messages too quickly. Wait a minute and try again.", 429);
    }

    $low = min($me, $otherId);
    $high = max($me, $otherId);

    try {
        $pdo->beginTransaction();

        // One row per pair, whoever writes first.
        $pdo->prepare("
            INSERT INTO admin_conversations (admin_low, admin_high) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE id = id
        ")->execute([$low, $high]);

        $stmt = $pdo->prepare("SELECT * FROM admin_conversations WHERE admin_low = ? AND admin_high = ? FOR UPDATE");
        $stmt->execute([$low, $high]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("INSERT INTO admin_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)")
            ->execute([(int) $conversation["id"], $me, $body]);
        $messageId = (int) $pdo->lastInsertId();

        // Sending means I've read everything up to my own message.
        $column = myReadColumn($conversation, $me);
        $pdo->prepare("UPDATE admin_conversations SET last_message_id = ?, $column = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$messageId, $messageId, (int) $conversation["id"]]);

        // Tracking: that a message went, to whom and when - never what it said.
        logEvent($pdo, EVENT_MESSAGE_SENT, "admin", $otherId, $other["name"],
            "Sent a message to " . $other["name"]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new PortalWriteError("Could not send the message.", 500);
    }

    $stmt = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM admin_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Marks my side of the conversation with $otherId read. False when there is none. */
function markConversationRead(PDO $pdo, $me, $otherId)
{
    $conversation = findConversation($pdo, (int) $me, (int) $otherId);
    if (!$conversation) {
        return false;
    }

    $column = myReadColumn($conversation, $me);
    $pdo->prepare("UPDATE admin_conversations SET $column = last_message_id WHERE id = ?")
        ->execute([(int) $conversation["id"]]);
    return true;
}
