<?php

/*
|--------------------------------------------------------------------------
| Editing and deleting your own message, in both kinds of chat
|--------------------------------------------------------------------------
|
| Shared by message-writes.php (staff chats) and client-message-writes.php
| (client chats), so the rule is one rule:
|
|   - only the person who sent a message changes it
|   - only for MESSAGE_EDIT_WINDOW seconds after it was sent, by the
|     database's clock - the same one that stamped created_at
|   - an edit keeps the message's place and marks it "edited"
|   - a delete clears the words in the database and leaves the message's
|     place as "Message deleted" for everyone; it can't be undone or edited
|
| Pages and api/v1 polling ask for "changed since": messageChangedClause()
| finds the messages edited or deleted after a point in time, so an edit or
| delete reaches everyone with the chat open on their next poll.
|
*/

const MESSAGE_EDIT_WINDOW = 300; // seconds

const MESSAGE_EDIT_NOT_YOURS = "You can only change your own messages.";
const MESSAGE_EDIT_TOO_LATE = "Messages can only be edited or deleted for 5 minutes after sending.";
const MESSAGE_EDIT_DELETED = "That message has been deleted.";

/**
 * Checks that the stored row $m (with age_seconds) may be changed by its
 * sender, $mine saying whether that is the caller. Throws PortalWriteError.
 */
function checkMessageChange(array $m, $mine)
{
    if (!$mine) {
        throw new PortalWriteError(MESSAGE_EDIT_NOT_YOURS, 403);
    }
    if ($m["deleted_at"] !== null) {
        throw new PortalWriteError(MESSAGE_EDIT_DELETED, 409);
    }
    if ((int) $m["age_seconds"] > MESSAGE_EDIT_WINDOW) {
        throw new PortalWriteError(MESSAGE_EDIT_TOO_LATE, 409);
    }
}

/** The new words for an edit, checked like a new message. Throws PortalWriteError. */
function checkEditedBody($rawBody, $maxChars)
{
    $body = trim(str_replace("\r\n", "\n", (string) $rawBody));
    if ($body === "") {
        throw new PortalWriteError("A message can't be empty. Delete it instead.", 400);
    }
    if (mb_strlen($body) > $maxChars) {
        throw new PortalWriteError("A message can be up to " . $maxChars . " characters.", 400);
    }
    return $body;
}

/**
 * Applies an edit ($body) or a delete ($body null) to message $id of $table.
 * The window is checked again in the UPDATE itself, so a change that arrives
 * just too late is refused even after checkMessageChange() passed. The
 * caller skips an edit that changes nothing, so no row touched means late.
 */
function applyMessageChange(PDO $pdo, $table, $id, $body)
{
    $set = $body === null ? "body = '', deleted_at = NOW()" : "body = ?, edited_at = NOW()";
    $stmt = $pdo->prepare("
        UPDATE `$table` SET $set
        WHERE id = ? AND deleted_at IS NULL AND created_at >= NOW() - INTERVAL " . MESSAGE_EDIT_WINDOW . " SECOND
    ");
    $stmt->execute($body === null ? [(int) $id] : [$body, (int) $id]);
    if ($stmt->rowCount() === 0) {
        throw new PortalWriteError(MESSAGE_EDIT_TOO_LATE, 409);
    }
}

/** The columns every message read adds for edits: SQL to append to a SELECT list. */
function messageEditColumns($alias = "m")
{
    return "$alias.edited_at, $alias.deleted_at,
            TIMESTAMPDIFF(SECOND, $alias.created_at, NOW()) AS age_seconds";
}

/** "...edited or deleted since $since" for a WHERE, with its one parameter. */
function messageChangedClause($since, $alias = "m")
{
    return ["($alias.edited_at >= FROM_UNIXTIME(?) OR $alias.deleted_at >= FROM_UNIXTIME(?))", [(int) $since, (int) $since]];
}

/** The database's clock now, as a unix time: the "changed since" a page sends next. */
function messageClock(PDO $pdo)
{
    return (int) $pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
}

/**
 * What every message row shows about edits: edited / deleted, and for the
 * sender, until when they can still change it (unix time, null after).
 */
function messageEditFields(array $m, $mine)
{
    $deleted = ($m["deleted_at"] ?? null) !== null;
    $left = MESSAGE_EDIT_WINDOW - (int) ($m["age_seconds"] ?? MESSAGE_EDIT_WINDOW + 1);
    return [
        "edited" => !$deleted && ($m["edited_at"] ?? null) !== null,
        "deleted" => $deleted,
        // Seconds the sender has left to edit or delete it, by the server's clock.
        "editable_for" => $mine && !$deleted && $left > 0 ? $left : 0,
    ];
}
