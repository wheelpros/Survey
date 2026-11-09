<?php

/*
|--------------------------------------------------------------------------
| Director tools for client accounts: changing the owner, merging two
|--------------------------------------------------------------------------
|
| The caller has checked clients.manage_accounts. Both run in one transaction, so
| they either happen whole or not at all, and neither can be undone by a button.
|
| Both first pin history to a name. A NULL *_by column means "the owner did it"
| (client-account.php), which stops being true the moment the owner, or the
| account the row belongs to, changes.
*/

require_once __DIR__ . "/client-team-writes.php";

const CLIENT_PERSON_COLUMNS = ["name", "email", "password", "session_token", "reset_token", "reset_expires", "profile_image", "phone", "whatsapp"];

/*
| Everything naming a person by id. Each entry: table, id column, and the kind
| column beside it where ids could also be an admin's.
*/
const CLIENT_PERSON_REFERENCES = [
    ["notifications", "recipient_id", "recipient_kind"],
    ["notifications", "actor_id", "actor_kind"],
    ["mcp_grants", "principal_id", "principal_kind"],
    ["mcp_confirmations", "principal_id", "principal_kind"],
    ["mcp_audit_log", "principal_id", "principal_kind"],
    ["survey_responses", "submitted_by_id", null],
    ["survey_uploaded_files", "uploaded_by_id", null],
    ["appointments", "requested_by_id", null],
    ["appointments", "responded_by_id", null],
    ["client_invites", "invited_by", null],
];

function clientTableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

/** Writes the owner's name onto an account's rows that only imply it. */
function pinClientAuthorship(PDO $pdo, $accountId, $ownerName)
{
    $accountId = (int) $accountId;

    $pdo->prepare("UPDATE survey_responses SET submitted_by_id = ?, submitted_by_name = ? WHERE user_id = ? AND submitted_by_id IS NULL")
        ->execute([$accountId, $ownerName, $accountId]);
    $pdo->prepare("UPDATE survey_uploaded_files SET uploaded_by_id = ? WHERE user_id = ? AND uploaded_by_id IS NULL")
        ->execute([$accountId, $accountId]);
    $pdo->prepare("UPDATE appointments SET requested_by_id = ?, requested_by_name = ? WHERE user_id = ? AND requested_by = 'user' AND requested_by_id IS NULL")
        ->execute([$accountId, $ownerName, $accountId]);
    $pdo->prepare("UPDATE appointments SET responded_by_id = ?, responded_by_name = ? WHERE user_id = ? AND requested_by = 'admin' AND status <> 'pending' AND responded_by_id IS NULL")
        ->execute([$accountId, $ownerName, $accountId]);
}

/**
 * Makes a teammate the owner. The owner's id is the account's id, which every
 * record hangs off, so the data stays put and the two people swap rows: each
 * keeps their login, session and history, and the old owner becomes a teammate.
 */
function changeClientOwner(PDO $pdo, array $admin, $accountId, $personId)
{
    $accountId = (int) $accountId;
    $personId = (int) $personId;

    ensureOAuthTables($pdo);

    $references = array_values(array_filter(CLIENT_PERSON_REFERENCES, function ($reference) use ($pdo) {
        return clientTableExists($pdo, $reference[0]);
    }));

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE");
        $stmt->execute([$accountId, $personId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row["id"]] = $row;
        }

        $owner = $rows[$accountId] ?? null;
        $person = $rows[$personId] ?? null;

        if (!$owner || !empty($owner["account_owner_id"])) {
            throw new PortalWriteError("Client not found.", 404);
        }
        if (!$person || $personId === $accountId || (int) $person["account_owner_id"] !== $accountId) {
            throw new PortalWriteError("That person isn't on this team.", 404);
        }

        pinClientAuthorship($pdo, $accountId, $owner["name"]);

        // Out of the way first, in case email or the session token is unique here.
        $pdo->prepare("UPDATE users SET email = ?, session_token = NULL WHERE id = ?")
            ->execute(["swapping-" . $personId . "@invalid.invalid", $personId]);

        $set = implode(", ", array_map(function ($column) {
            return "`$column` = ?";
        }, CLIENT_PERSON_COLUMNS));
        $write = $pdo->prepare("UPDATE users SET $set WHERE id = ?");

        $values = function (array $row) {
            return array_map(function ($column) use ($row) {
                return $row[$column] ?? null;
            }, CLIENT_PERSON_COLUMNS);
        };

        $pdo->prepare("UPDATE users SET email = ?, session_token = NULL WHERE id = ?")
            ->execute(["swapping-" . $accountId . "@invalid.invalid", $accountId]);

        $write->execute(array_merge($values($person), [$accountId]));
        $write->execute(array_merge($values($owner), [$personId]));

        // One statement per column, so an id can't be swapped back by its partner.
        foreach ($references as [$table, $column, $kindColumn]) {
            $kind = $kindColumn ? " AND `$kindColumn` = 'user'" : "";
            $pdo->prepare("
                UPDATE `$table`
                SET `$column` = CASE `$column` WHEN ? THEN ? ELSE ? END
                WHERE `$column` IN (?, ?)$kind
            ")->execute([$accountId, $personId, $accountId, $accountId, $personId]);
        }

        $who = eventClientName($pdo, $accountId);
        logEvent($pdo, EVENT_CLIENT_OWNER_CHANGED, "user", $accountId, $who,
            "Made " . $person["name"] . " the owner of " . $who . "'s account, in place of " . $owner["name"], $accountId);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Their rows have swapped: the new owner now answers to the account's id.
    notify($pdo, "user", $accountId, NOTIFY_CLIENT_TEAM_CHANGED,
        "You're now the owner of this account", "You manage its team and company details.", "profile.html#team", "admin", (int) $admin["id"]);
    notify($pdo, "user", $personId, NOTIFY_CLIENT_TEAM_CHANGED,
        $person["name"] . " is now the owner of this account", "", "profile.html#team", "admin", (int) $admin["id"]);

    return ["new_owner" => $person["name"], "old_owner" => $owner["name"]];
}

/** The two accounts, checked, and what merging them would move. Throws if it can't happen. */
function clientMergePlan(PDO $pdo, $sourceId, $targetId, $lock = false)
{
    $sourceId = (int) $sourceId;
    $targetId = (int) $targetId;

    if ($sourceId === $targetId) {
        throw new PortalWriteError("Pick a different account to merge in.");
    }

    $stmt = $pdo->prepare("SELECT id, name, email, company_name, account_owner_id, approved FROM users WHERE id IN (?, ?) ORDER BY id" . ($lock ? " FOR UPDATE" : ""));
    $stmt->execute([$sourceId, $targetId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row["approved"] === 1 && empty($row["account_owner_id"])) {
            $rows[(int) $row["id"]] = $row;
        }
    }

    if (!isset($rows[$sourceId], $rows[$targetId])) {
        throw new PortalWriteError("Both must be approved client accounts.", 404);
    }

    $seats = clientTeamSeatsUsed($pdo, $targetId) + clientTeamSeatsUsed($pdo, $sourceId) + 1;

    if ($seats > CLIENT_TEAM_MAX_TEAMMATES) {
        throw new PortalWriteError(
            "Together they'd have " . ($seats + 1) . " people, counting pending invites, and an account can have "
            . (CLIENT_TEAM_MAX_TEAMMATES + 1) . ". Remove someone first.",
            409
        );
    }

    $counts = [];
    foreach (["surveys" => "forms", "projects" => "projects", "appointments" => "meetings", "survey_uploaded_files" => "files"] as $table => $label) {
        $column = CLIENT_TENANT_COLUMNS[$table];
        $count = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $count->execute([$sourceId]);
        $counts[$label] = (int) $count->fetchColumn();
    }
    $counts["people"] = count(clientAccountPeople($pdo, $sourceId));

    return [
        "source" => ["id" => $sourceId, "name" => $rows[$sourceId]["name"], "email" => $rows[$sourceId]["email"], "company_name" => $rows[$sourceId]["company_name"]],
        "target" => ["id" => $targetId, "name" => $rows[$targetId]["name"], "email" => $rows[$targetId]["email"], "company_name" => $rows[$targetId]["company_name"]],
        "counts" => $counts,
        "names_differ" => trim((string) $rows[$sourceId]["company_name"]) !== trim((string) $rows[$targetId]["company_name"]),
    ];
}

/**
 * Moves everything of $sourceId's onto $targetId and makes its owner a teammate
 * there. Upload paths are stored in full on each row, so the files stay put.
 */
function mergeClientAccounts(PDO $pdo, array $admin, $sourceId, $targetId)
{
    $sourceId = (int) $sourceId;
    $targetId = (int) $targetId;

    $pdo->beginTransaction();

    try {
        $plan = clientMergePlan($pdo, $sourceId, $targetId, true);

        pinClientAuthorship($pdo, $sourceId, $plan["source"]["name"]);

        // Where both already have the row, the target's stands; a staff member's
        // private notes on the two are kept together.
        if (clientTableExists($pdo, "client_admin_notes")) {
            $pdo->prepare("
                UPDATE client_admin_notes t
                JOIN client_admin_notes s ON s.admin_id = t.admin_id AND s.user_id = ?
                SET t.note = CONCAT(t.note, '\n\n', s.note)
                WHERE t.user_id = ?
            ")->execute([$sourceId, $targetId]);
        }

        foreach (["admin_user_assignments" => "admin_id", "client_admin_notes" => "admin_id", "user_source_files" => "file_id"] as $table => $key) {
            if (clientTableExists($pdo, $table)) {
                $pdo->prepare("
                    DELETE s FROM `$table` s
                    JOIN `$table` t ON t.`$key` = s.`$key` AND t.user_id = ?
                    WHERE s.user_id = ?
                ")->execute([$targetId, $sourceId]);
            }
        }

        foreach (CLIENT_TENANT_COLUMNS as $table => $column) {
            if (clientTableExists($pdo, $table)) {
                $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE `$column` = ?")->execute([$targetId, $sourceId]);
            }
        }

        $pdo->prepare("UPDATE admin_events SET client_id = ? WHERE client_id = ?")->execute([$targetId, $sourceId]);
        $pdo->prepare("UPDATE client_invites SET account_id = ? WHERE account_id = ?")->execute([$targetId, $sourceId]);

        // Posts find their client by name. They follow, unless another account
        // still answers to the old one. A target with no name takes the source's.
        $oldCompany = trim((string) $plan["source"]["company_name"]);
        $newCompany = trim((string) $plan["target"]["company_name"]);

        if ($newCompany === "" && $oldCompany !== "") {
            $pdo->prepare("UPDATE users SET company_name = ? WHERE id = ?")->execute([$oldCompany, $targetId]);
        } elseif ($oldCompany !== "" && $oldCompany !== $newCompany) {
            $shared = $pdo->prepare("SELECT COUNT(*) FROM users WHERE approved = 1 AND company_name = ? AND id NOT IN (?, ?)");
            $shared->execute([$oldCompany, $sourceId, $targetId]);

            if ((int) $shared->fetchColumn() === 0) {
                $pdo->prepare("UPDATE content SET client = ? WHERE client = ?")->execute([$newCompany, $oldCompany]);
            }
        }

        $pdo->prepare("UPDATE users SET account_owner_id = ? WHERE account_owner_id = ?")->execute([$targetId, $sourceId]);
        $pdo->prepare("UPDATE users SET account_owner_id = ?, company_name = NULL, website = NULL, description = NULL WHERE id = ?")
            ->execute([$targetId, $sourceId]);

        $who = eventClientName($pdo, $targetId);
        logEvent($pdo, EVENT_CLIENT_ACCOUNTS_MERGED, "user", $targetId, $who,
            "Merged account #" . $sourceId . " (" . $plan["source"]["email"] . ") into " . $who . ": " . json_encode($plan["counts"]), $targetId);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    notifyClientAccount($pdo, $targetId, NOTIFY_CLIENT_TEAM_CHANGED,
        "Two accounts were merged into this one", "Everyone on them now shares the same forms, projects and meetings.", "profile.html#team", "admin", (int) $admin["id"]);

    return $plan;
}
