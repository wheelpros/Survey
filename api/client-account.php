<?php

/*
|--------------------------------------------------------------------------
| A client account and the people on it
|--------------------------------------------------------------------------
|
| An account is its owner's users row: the first person registered, and
| everything of the client's already hangs off that id. A teammate is another
| users row with account_owner_id pointing at it. So:
|
|   account_id  owner's users.id. What every client record is scoped by:
|               surveys.assigned_user_id, projects.client_id, appointments.user_id
|               and the rest of CLIENT_TENANT_COLUMNS. Always this, never a
|               teammate's own id.
|   id          the person. What sessions, notifications and AI connections are
|               keyed by, and who the *_by columns below name.
|
| Company details live on the owner's row alone. A teammate's company_name stays
| NULL, so nothing that matches on it counts them as an account of their own,
| and staff assignments stay on the owner too.
|
| *_by columns record who really acted, with a name kept for when they've left.
| NULL means the row is older than teams, so the owner did it.
|
| Added lazily (see the bottom of db.php); sql/client-team.sql is the same change
| to run by hand when the DB user may not ALTER.
*/

const CLIENT_TEAM_MAX_TEAMMATES = 4;

const CLIENT_AUTHOR_COLUMNS = [
    "survey_responses" => [
        "submitted_by_id" => "INT NULL",
        "submitted_by_name" => "VARCHAR(150) NULL",
    ],
    "survey_uploaded_files" => [
        "uploaded_by_id" => "INT NULL",
    ],
    "appointments" => [
        "requested_by_id" => "INT NULL",
        "requested_by_name" => "VARCHAR(150) NULL",
        "responded_by_id" => "INT NULL",
        "responded_by_name" => "VARCHAR(150) NULL",
        "responded_at" => "DATETIME NULL",
    ],
];

function ensureClientTeamSchema(PDO $pdo)
{
    try {
        $tables = array_merge(["users"], array_keys(CLIENT_AUTHOR_COLUMNS));
        $stmt = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('" . implode("','", $tables) . "')
        ");

        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row["TABLE_NAME"]][] = $row["COLUMN_NAME"];
        }

        if (!isset($existing["users"])) {
            return;
        }

        if (!in_array("account_owner_id", $existing["users"], true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN account_owner_id INT NULL, ADD KEY idx_account_owner (account_owner_id)");
        }

        // A first request on a new install creates this table on its way to
        // writing to it, so it can't wait for the next request to get its columns.
        if (!isset($existing["appointments"])) {
            ensureAppointmentTables($pdo);
            $existing["appointments"] = [];
        }

        foreach (CLIENT_AUTHOR_COLUMNS as $table => $columns) {
            if (!isset($existing[$table])) {
                continue;
            }
            foreach ($columns as $column => $type) {
                if (!in_array($column, $existing[$table], true)) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
                }
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_invites (
              id          INT AUTO_INCREMENT PRIMARY KEY,
              account_id  INT          NOT NULL,
              email       VARCHAR(190) NOT NULL,
              token_hash  CHAR(64)     NOT NULL,
              invited_by  INT          NOT NULL,
              expires_at  INT          NOT NULL,
              accepted_at DATETIME         NULL,
              revoked_at  DATETIME         NULL,
              created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_token (token_hash),
              KEY idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: run sql/client-team.sql by hand.
        error_log("client teams: could not update the schema: " . $e->getMessage());
    }
}

/**
 * A users row with what its account adds: account_id, is_account_owner, and the
 * owner's company details in place of the teammate's empty ones. Credentials are
 * dropped, since callers go on to build replies from it. Null when a teammate's
 * owner is no longer an approved account.
 */
function withClientAccount(PDO $pdo, array $row)
{
    $ownerId = (int) ($row["account_owner_id"] ?? 0);

    $row["account_id"] = $ownerId ?: (int) $row["id"];
    $row["is_account_owner"] = $ownerId === 0;

    unset($row["password"], $row["session_token"], $row["reset_token"], $row["reset_expires"]);

    if ($ownerId) {
        $stmt = $pdo->prepare("SELECT company_name, website, description FROM users WHERE id = ? AND approved = 1 LIMIT 1");
        $stmt->execute([$ownerId]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$owner) {
            return null;
        }

        $row = array_merge($row, $owner);
    }

    return $row;
}

/** The approved client behind a browser session, or null. */
function clientFromSessionToken(PDO $pdo, $token)
{
    if ((string) $token === "") {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int) $row["approved"] !== 1) {
        return null;
    }

    return withClientAccount($pdo, $row);
}

/** Everyone on an account, owner first. */
function clientAccountPeople(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, name, email, profile_image, created_at, account_owner_id, approved
        FROM users
        WHERE approved = 1 AND (id = ? OR account_owner_id = ?)
        ORDER BY account_owner_id IS NOT NULL, created_at ASC, id ASC
    ");
    $stmt->execute([(int) $accountId, (int) $accountId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function clientAccountPeopleIds(PDO $pdo, $accountId)
{
    return array_map("intval", array_column(clientAccountPeople($pdo, $accountId), "id"));
}
