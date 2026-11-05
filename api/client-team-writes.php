<?php

/*
|--------------------------------------------------------------------------
| A client's team: inviting, removing and joining
|--------------------------------------------------------------------------
|
| Shared by my-team.php, accept-invite.php, admin-user-details.php and api/v1, so
| the limit and who may do what can't differ between them. Throws
| PortalWriteError with the message to show.
|
| Not to be confused with client-team.php, which is the staff who work on a client.
|
| An invite is not an account: the users row appears when it is accepted, and
| following the link is what proves the address is theirs. A live invite holds a
| seat, so an owner can't send more invites than the team can take. Only the
| token's hash is kept, as with MCP tokens.
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/upload-helpers.php";
require_once __DIR__ . "/oauth/lib.php";

const CLIENT_INVITE_TTL = 72 * 3600;

const CLIENT_INVITE_GONE = "This invitation is no longer valid. Ask whoever invited you to send a new one.";

function requireAccountOwner(array $user)
{
    if (empty($user["is_account_owner"])) {
        throw new PortalWriteError("Only the account owner can change the team.", 403);
    }
}

/** Teammates plus live invites. Call inside the transaction holding the owner's row. */
function clientTeamSeatsUsed(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM users WHERE account_owner_id = ?)
          + (SELECT COUNT(*) FROM client_invites
             WHERE account_id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?)
    ");
    $stmt->execute([(int) $accountId, (int) $accountId, time()]);

    return (int) $stmt->fetchColumn();
}

/** Serialises every change to one team, so two requests can't both take the last seat. */
function lockClientAccount(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT id, name, company_name FROM users WHERE id = ? AND approved = 1 AND account_owner_id IS NULL FOR UPDATE");
    $stmt->execute([(int) $accountId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function refuseFullTeam(PDO $pdo, $accountId)
{
    if (clientTeamSeatsUsed($pdo, $accountId) >= CLIENT_TEAM_MAX_TEAMMATES) {
        throw new PortalWriteError(
            "A team can have " . CLIENT_TEAM_MAX_TEAMMATES . " teammates. Remove someone or revoke an invite to add another.",
            409
        );
    }
}

/** Whether an address is taken: logins are found by email, in either table. */
function clientEmailInUse(PDO $pdo, $email)
{
    foreach (["users", "admins"] as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

/** The people on an account, plus its pending invites for the owner. */
function clientTeamSnapshot(PDO $pdo, array $user)
{
    $accountId = (int) $user["account_id"];

    $people = array_map(function ($row) {
        return [
            "id" => (int) $row["id"],
            "name" => (string) $row["name"],
            "email" => (string) $row["email"],
            "is_owner" => empty($row["account_owner_id"]),
            "joined_at" => (string) $row["created_at"],
        ];
    }, clientAccountPeople($pdo, $accountId));

    $team = [
        "people" => $people,
        "max_teammates" => CLIENT_TEAM_MAX_TEAMMATES,
        "can_manage" => !empty($user["is_account_owner"]),
        "invites" => [],
    ];

    if ($team["can_manage"]) {
        $team["invites"] = clientPendingInvites($pdo, $accountId);
    }

    return $team;
}

/** Invites not yet accepted or revoked. An expired one stays listed, to resend. */
function clientPendingInvites(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, email, expires_at
        FROM client_invites
        WHERE account_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
        ORDER BY id ASC
    ");
    $stmt->execute([(int) $accountId]);

    return array_map(function ($row) {
        return [
            "id" => (int) $row["id"],
            "email" => (string) $row["email"],
            "expires_at" => date("Y-m-d H:i:s", (int) $row["expires_at"]),
            "expired" => (int) $row["expires_at"] <= time(),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Returns whether the email went; the invite stands either way, and can be resent. */
function emailClientInvite(array $owner, $company, $email, $token)
{
    $portal = portalName();
    $who = trim((string) $company) !== "" ? trim((string) $company) : $owner["name"];

    $html = adminEmailHtml(
        "Join " . $who . " on " . $portal,
        $owner["name"] . " invited you to work with them on " . $portal . ". Set a password to join. The link works for 3 days.",
        ["Company" => $company, "Invited by" => $owner["name"]],
        "join-team.html?token=" . $token,
        "Accept the invitation"
    );

    return sendPortalEmail([["email" => $email, "name" => ""]], "Join " . $who . " on " . $portal, $html) > 0;
}

function inviteTeammate(PDO $pdo, array $owner, $email)
{
    requireAccountOwner($owner);

    $email = strtolower(trim((string) $email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        throw new PortalWriteError("That does not look like an email address.");
    }

    $accountId = (int) $owner["account_id"];
    $token = bin2hex(random_bytes(32));

    $pdo->beginTransaction();

    try {
        if (!lockClientAccount($pdo, $accountId)) {
            throw new PortalWriteError("Your account could not be found.", 404);
        }

        refuseFullTeam($pdo, $accountId);

        if (clientEmailInUse($pdo, $email)) {
            throw new PortalWriteError("That email already has an account here, so it can't be invited.", 409);
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM client_invites
            WHERE account_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?
            LIMIT 1
        ");
        $stmt->execute([$accountId, $email, time()]);

        if ($stmt->fetchColumn()) {
            throw new PortalWriteError("You've already invited that email. Resend the invite if it didn't arrive.", 409);
        }

        $pdo->prepare("
            INSERT INTO client_invites (account_id, email, token_hash, invited_by, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$accountId, $email, hash("sha256", $token), (int) $owner["id"], time() + CLIENT_INVITE_TTL]);

        $inviteId = (int) $pdo->lastInsertId();

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ["id" => $inviteId, "email" => $email, "emailed" => emailClientInvite($owner, $owner["company_name"] ?? "", $email, $token)];
}

/**
 * The invite, if it can still be sent again. Runs $then inside the account's
 * lock, once these checks have passed, and returns the invite.
 */
function withResendableInvite(PDO $pdo, $accountId, $inviteId, ?callable $then = null)
{
    $pdo->beginTransaction();

    try {
        lockClientAccount($pdo, $accountId);

        $stmt = $pdo->prepare("
            SELECT id, email, expires_at FROM client_invites
            WHERE id = ? AND account_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([(int) $inviteId, $accountId]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invite) {
            throw new PortalWriteError("That invite is no longer open.", 404);
        }

        // An expired invite gave up its seat, which may have gone since.
        if ((int) $invite["expires_at"] <= time()) {
            refuseFullTeam($pdo, $accountId);
        }

        // The address may have got an account of its own since it was invited.
        if (clientEmailInUse($pdo, $invite["email"])) {
            throw new PortalWriteError("That email already has an account here, so it can't be invited.", 409);
        }

        if ($then) {
            $then($invite);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $invite;
}

/**
 * A new token and three more days, but only once the email has gone: the old
 * link stops working then, and not before, so a mail server that is down never
 * costs someone the link they already have.
 *
 * The email goes out between two locks rather than inside one, since a slow
 * mail server would otherwise hold the whole account up. The second lock checks
 * again in case the invite was revoked or the team filled up in that time.
 */
function resendClientInvite(PDO $pdo, array $owner, $inviteId)
{
    requireAccountOwner($owner);

    $accountId = (int) $owner["account_id"];
    $token = bin2hex(random_bytes(32));

    $invite = withResendableInvite($pdo, $accountId, $inviteId);

    $emailed = emailClientInvite($owner, $owner["company_name"] ?? "", $invite["email"], $token);

    if ($emailed) {
        withResendableInvite($pdo, $accountId, $inviteId, function (array $open) use ($pdo, $token) {
            $pdo->prepare("UPDATE client_invites SET token_hash = ?, expires_at = ? WHERE id = ?")
                ->execute([hash("sha256", $token), time() + CLIENT_INVITE_TTL, (int) $open["id"]]);
        });
    }

    return ["id" => (int) $invite["id"], "email" => $invite["email"], "emailed" => $emailed];
}

function revokeClientInvite(PDO $pdo, array $owner, $inviteId)
{
    requireAccountOwner($owner);

    $stmt = $pdo->prepare("
        UPDATE client_invites SET revoked_at = NOW()
        WHERE id = ? AND account_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
    ");
    $stmt->execute([(int) $inviteId, (int) $owner["account_id"]]);

    if ($stmt->rowCount() === 0) {
        throw new PortalWriteError("That invite is no longer open.", 404);
    }
}

/**
 * Takes a teammate off an account and returns their row, or throws. Deleting the
 * row ends their session and frees the address; revoking the grants ends any AI
 * connection. Their past work stays with the account, under the name kept on it.
 * Whoever calls this has already decided they may.
 */
function deleteTeammate(PDO $pdo, $accountId, $personId)
{
    $stmt = $pdo->prepare("SELECT id, name, email, profile_image FROM users WHERE id = ? AND account_owner_id = ? LIMIT 1");
    $stmt->execute([(int) $personId, (int) $accountId]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        throw new PortalWriteError("That person isn't on this team.", 404);
    }

    $pdo->beginTransaction();

    try {
        $grants = $pdo->prepare("SELECT id FROM mcp_grants WHERE principal_kind = 'user' AND principal_id = ?");
        $grants->execute([(int) $person["id"]]);

        foreach ($grants->fetchAll(PDO::FETCH_COLUMN) as $grantId) {
            revokeGrant($pdo, (int) $grantId);
        }

        $pdo->prepare("DELETE FROM notifications WHERE recipient_kind = 'user' AND recipient_id = ?")->execute([(int) $person["id"]]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $person["id"]]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    deleteUploadedFiles(array_filter([$person["profile_image"]]));

    return $person;
}

/** Tells the staff on a client that its team changed. After any commit. */
function notifyStaffOfTeamChange(PDO $pdo, $accountId, $actorId, $title)
{
    notifyAdminsAboutUser(
        $pdo,
        meetingAdminIdsForUser($pdo, (int) $accountId),
        (int) $actorId,
        NOTIFY_CLIENT_TEAM_CHANGED,
        $title,
        "",
        "user-details.html?id=" . (int) $accountId
    );
}

function removeTeammate(PDO $pdo, array $owner, $personId)
{
    requireAccountOwner($owner);

    $person = deleteTeammate($pdo, (int) $owner["account_id"], $personId);

    notifyStaffOfTeamChange($pdo, $owner["account_id"], $owner["id"],
        $owner["name"] . " removed " . $person["name"] . " from their team");

    return $person;
}

/** A live invite by its token, with who it's from, or null. */
function findClientInvite(PDO $pdo, $token, $lock = false)
{
    $token = (string) $token;

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.account_id, i.email, u.name AS owner_name, u.company_name
        FROM client_invites i
        JOIN users u ON u.id = i.account_id AND u.approved = 1
        WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?
        LIMIT 1" . ($lock ? " FOR UPDATE" : "")
    );
    $stmt->execute([hash("sha256", $token), time()]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Turns an invite into a login, and returns the invite. */
function acceptClientInvite(PDO $pdo, $token, $name, $password)
{
    $name = trim((string) $name);
    $password = (string) $password;

    if ($name === "" || mb_strlen($name) > 150) {
        throw new PortalWriteError("Enter your name.");
    }
    if (strlen($password) < 8) {
        throw new PortalWriteError("Choose a password of at least 8 characters.");
    }

    $pdo->beginTransaction();

    try {
        $invite = findClientInvite($pdo, $token, true);

        if (!$invite || !lockClientAccount($pdo, $invite["account_id"])) {
            throw new PortalWriteError(CLIENT_INVITE_GONE, 410);
        }

        // Live invites can't outnumber the seats when sent, but a seat counted for
        // an invite that lapsed may have gone before this one was resent.
        $count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_owner_id = ?");
        $count->execute([(int) $invite["account_id"]]);

        if ((int) $count->fetchColumn() >= CLIENT_TEAM_MAX_TEAMMATES) {
            throw new PortalWriteError("This team is full. Ask whoever invited you to make room.", 409);
        }

        if (clientEmailInUse($pdo, $invite["email"])) {
            throw new PortalWriteError("That email already has an account here, so sign in with it instead.", 409);
        }

        $pdo->prepare("
            INSERT INTO users (name, email, password, approved, account_owner_id)
            VALUES (?, ?, ?, 1, ?)
        ")->execute([$name, $invite["email"], password_hash($password, PASSWORD_DEFAULT), (int) $invite["account_id"]]);

        $personId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE client_invites SET accepted_at = NOW() WHERE id = ?")->execute([(int) $invite["id"]]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    notify($pdo, "user", (int) $invite["account_id"], NOTIFY_CLIENT_TEAM_CHANGED,
        $name . " joined your team", "", "team.html", "user", $personId);
    notifyStaffOfTeamChange($pdo, $invite["account_id"], $personId,
        $name . " joined " . (trim((string) $invite["company_name"]) ?: $invite["owner_name"]) . "'s team");

    return $invite;
}
