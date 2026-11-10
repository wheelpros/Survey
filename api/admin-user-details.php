<?php

require_once "db.php";
require_once "events.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

function respond($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);

    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authenticate the admin
|--------------------------------------------------------------------------
|
| Whoever holds clients.details may view a user - by default every role but
| seo_admin, the same people who see Clients Management, the only page that
| opens this one. Anyone else gets a 403 on read and write.
|
| The role is read alongside the id because one thing on this page is narrower
| than the page: the internal note below is the account manager's and the
| owner's to write (clients.edit).
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? $_SERVER["HTTP_AUTHORIZATION"]
    ?? "";

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    respond(false, "Unauthorized.", [], 401);
}

try {
    $stmt = $pdo->prepare("SELECT id, role FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([trim($matches[1])]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        respond(false, "Unauthorized.", [], 401);
    }
} catch (Throwable $e) {
    respond(false, "Authentication database error.", [], 500);
}

if (!can($admin, "clients.details", $pdo)) {
    respond(false, "Clients Management is not available to your role.", [], 403);
}

/* Who may change what is on this page: the internal note, and the client's own
   details. Every other admin reads both and no more.

   Approving, rejecting and deleting are not in here - those are
   clients.approve and clients.delete, enforced by api/admin-users.php and
   api/delete-client.php. */
$canManageNotes = can($admin, "clients.edit", $pdo);
$canEditDetails = $canManageNotes;

/*
|--------------------------------------------------------------------------
| Saving the internal note
|--------------------------------------------------------------------------
|
| The one write this endpoint takes. It comes before the read below because a
| POST has no business building the whole profile first.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $input["action"] ?? "";

    if (!in_array($action, ["save_notes", "save_details", "remove_teammate", "change_owner", "merge_preview", "merge"], true)) {
        respond(false, "Unknown action.", [], 400);
    }

    $accountAction = in_array($action, ["change_owner", "merge_preview", "merge"], true);

    if ($accountAction && !can($admin, "clients.manage_accounts", $pdo)) {
        respond(false, "Only the Director can change a client's owner or merge accounts.", [], 403);
    }

    if (!$canEditDetails && !$accountAction) {
        respond(false, "Only an account manager or the Director can edit a client.", [], 403);
    }

    $targetId = (int) ($input["id"] ?? 0);

    if (!$targetId) {
        respond(false, "A numeric user id is required.", [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | The client's own details
    |--------------------------------------------------------------------------
    |
    | The same five fields the client edits on profile.html, editable here so an
    | account manager can correct a number or fill in a company the client left
    | blank without asking them to log in and do it.
    |
    | Deliberately not here: name and email are the account's identity, and
    | approved is a decision rather than a detail - both have their own
    | endpoints, with their own rules about who may change them.
    |
    | Each value gets the same treatment api/update-profile.php gives it, so a
    | number saved here and a number saved there end up in the same shape.
    |
    */

    if ($accountAction) {
        require_once "client-account-writes.php";

        $sourceId = (int) ($input["source_id"] ?? 0);

        try {
            if ($action === "change_owner") {
                $done = changeClientOwner($pdo, $admin, $targetId, (int) ($input["person_id"] ?? 0));
                respond(true, $done["new_owner"] . " is now the owner of this account.");
            }

            if ($action === "merge_preview") {
                respond(true, "", ["plan" => clientMergePlan($pdo, $sourceId, $targetId)]);
            }

            $plan = mergeClientAccounts($pdo, $admin, $sourceId, $targetId);
            respond(true, $plan["source"]["name"] . "'s account has been merged into this one.");

        } catch (PortalWriteError $e) {
            respond(false, $e->getMessage(), [], $e->status);
        }
    }

    /*
    | Taking someone off a client's team - the same as the owner doing it, plus
    | a line in the event log. Only for a client this admin works on.
    */
    if ($action === "remove_teammate") {

        if (!can($admin, "clients.view_all", $pdo)) {
            $assigned = $pdo->prepare("SELECT 1 FROM admin_user_assignments WHERE admin_id = ? AND user_id = ? LIMIT 1");
            $assigned->execute([(int) $admin["id"], $targetId]);

            if (!$assigned->fetchColumn()) {
                respond(false, "User not found.", [], 404);
            }
        }

        require_once "client-team-writes.php";

        try {
            $person = deleteTeammate($pdo, $targetId, (int) ($input["person_id"] ?? 0));
        } catch (PortalWriteError $e) {
            respond(false, $e->getMessage(), [], $e->status);
        }

        $who = eventClientName($pdo, $targetId);
        logEvent($pdo, EVENT_CLIENT_TEAMMATE_REMOVED, "user", $targetId, $who,
            "Removed " . $person["name"] . " from " . $who . "'s team", $targetId);

        notify($pdo, "user", $targetId, NOTIFY_CLIENT_TEAM_CHANGED,
            $person["name"] . " was removed from your team", "", "profile.html#team", "admin", (int) $admin["id"]);

        respond(true, $person["name"] . " is no longer on this team.");
    }

    if ($action === "save_details") {

        ensureUserProfileColumns($pdo);

        $company = trim((string) ($input["company_name"] ?? ""));
        $phone = trim((string) ($input["phone"] ?? ""));
        $about = trim((string) ($input["description"] ?? ""));
        $websiteInput = trim((string) ($input["website"] ?? ""));
        $whatsappInput = trim((string) ($input["whatsapp"] ?? ""));

        if (mb_strlen($company) > 150) {
            respond(false, "The company name has to be 150 characters or fewer.", [], 400);
        }

        if ($phone !== "" && !preg_match("/^[0-9+\-\s().]{6,25}$/", $phone)) {
            respond(false, "That does not look like a phone number.", [], 400);
        }

        // A bare domain is how it is usually typed; store something linkable.
        $website = normaliseWebUrl($websiteInput);

        if ($websiteInput !== "" && $website === "") {
            respond(false, "That does not look like a web address.", [], 400);
        }

        // The country code is the part worth insisting on - see db.php.
        $whatsapp = normaliseWhatsApp($whatsappInput);

        if ($whatsappInput !== "" && $whatsapp === "") {
            respond(false, "Start the WhatsApp number with its country code, like +44 7911 123456.", [], 400);
        }

        try {
            $check = $pdo->prepare("SELECT id, company_name FROM users WHERE id = ? AND account_owner_id IS NULL LIMIT 1");
            $check->execute([$targetId]);
            $current = $check->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                respond(false, "User not found.", [], 404);
            }

            $pdo->beginTransaction();

            $save = $pdo->prepare("
                UPDATE users
                SET company_name = ?, website = ?, description = ?, phone = ?, whatsapp = ?
                WHERE id = ?
            ");

            $save->execute([
                $company === "" ? null : $company,
                $website === "" ? null : $website,
                $about === "" ? null : $about,
                $phone === "" ? null : $phone,
                $whatsapp === "" ? null : $whatsapp,
                $targetId
            ]);

            /* Posts find their client by this name, so a rename takes them along -
               unless another approved account still answers to the old name, whose
               posts these also are. Clearing the name leaves them where they are. */
            $oldCompany = trim((string) $current["company_name"]);

            if ($oldCompany !== "" && $company !== "" && $oldCompany !== $company) {
                $shared = $pdo->prepare("SELECT COUNT(*) FROM users WHERE approved = 1 AND company_name = ? AND id <> ?");
                $shared->execute([$oldCompany, $targetId]);

                if ((int) $shared->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE content SET client = ? WHERE client = ?")->execute([$company, $oldCompany]);
                }
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            respond(false, "Could not save the details.", [], 500);
        }

        $who = eventClientName($pdo, $targetId);
        logEvent($pdo, EVENT_CLIENT_UPDATED, "user", $targetId, $who, "Edited " . $who . "'s company details", $targetId);

        /* The saved values go back, not the submitted ones: the page repaints
           from these, so what it shows is what is stored rather than what was
           typed. */
        respond(true, "Details saved.", [
            "details" => [
                "company_name" => $company,
                "website" => $website,
                "description" => $about,
                "phone" => $phone,
                "whatsapp" => $whatsapp
            ]
        ]);
    }

    $note = trim((string) ($input["notes"] ?? ""));

    // TEXT holds far more than anyone types into a note field, but a request
    // is not a promise about its own size.
    if (mb_strlen($note) > 5000) {
        respond(false, "A note has to be 5000 characters or fewer.", [], 400);
    }

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $check->execute([$targetId]);

        if (!$check->fetch()) {
            respond(false, "User not found.", [], 404);
        }
    } catch (Throwable $e) {
        respond(false, "Could not save the note.", [], 500);
    }

    /* Saved against the admin who wrote it: it is theirs to read back and
       nobody else's, so the note of whoever else has written one about this
       client is neither read nor touched here. */
    if (!setClientNote($pdo, $targetId, (int) $admin["id"], $note)) {
        respond(false, "Could not save the note.", [], 500);
    }

    $who = eventClientName($pdo, $targetId);
    logEvent($pdo, EVENT_CLIENT_NOTE_SAVED, "user", $targetId, $who,
        ($note === "" ? "Cleared their private note about " : "Saved their private note about ") . $who, $targetId);

    respond(true, $note === "" ? "Note cleared." : "Note saved.", [
        "notes" => $note
    ]);
}

/*
|--------------------------------------------------------------------------
| The user
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id"]) || !ctype_digit((string) $_GET["id"])) {
    respond(false, "A numeric user id is required.", [], 400);
}

$userId = (int) $_GET["id"];

// company_name, website, description and phone are added lazily by db.php.
ensureUserProfileColumns($pdo);

try {
    // Explicit columns: never expose password or session_token.
    $stmt = $pdo->prepare("
        SELECT
            id, name, email, phone, whatsapp,
            company_name, website, description,
            profile_image, approved, created_at
        FROM users
        WHERE id = ? AND account_owner_id IS NULL
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    respond(false, "Could not load the user.", [], 500);
}

if (!$user) {
    respond(false, "User not found.", [], 404);
}

/* The address to open, worked out here rather than on the page: it is the same
   rule wa.me needs everywhere, and an empty string is the page's cue that there
   is no icon to draw. */
$user["whatsapp_link"] = whatsAppLink($user["whatsapp"] ?? "");

/* Approving a sign-up gives it that company's posts and files, so the page warns
   when the name it typed is already an approved account's. */
$user["company_matches"] = 0;

if ((int) $user["approved"] !== 1 && trim((string) $user["company_name"]) !== "") {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE approved = 1 AND company_name = ? AND id <> ?");
    $stmt->execute([trim($user["company_name"]), $userId]);
    $user["company_matches"] = (int) $stmt->fetchColumn();
}

/* The internal note, and only this admin's own: a note is private to whoever
   wrote it, so another admin's note about this client is not fetched here and
   there is nothing on this response for the page to leak. An admin who has
   written none gets an empty string, which is also what the page shows every
   admin who may not write one at all. */
$user["admin_notes"] = $canManageNotes
    ? getClientNote($pdo, $userId, (int) $admin["id"])
    : "";

/*
|--------------------------------------------------------------------------
| Activity
|--------------------------------------------------------------------------
|
| Each list is the query the matching admin page already runs, narrowed to
| this user. They are wrapped individually so a deploy missing one table
| still renders the profile instead of failing the whole request.
|
*/

function fetchList(PDO $pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Still an empty list for the page, but never a silent one.
        error_log("admin-user-details.php: " . $e->getMessage());
        return [];
    }
}

// The calendar's three sources, so a fresh database has the columns they read.
ensureAppointmentTables($pdo);
ensureProjectTables($pdo);
try {
    ensureContentColumns($pdo);
} catch (Throwable $e) {
    error_log("admin-user-details.php: content columns: " . $e->getMessage());
}

$surveys = fetchList($pdo, "
    SELECT id, title, status, created_at
    FROM surveys
    WHERE assigned_user_id = ?
    ORDER BY created_at DESC
", [$userId]);

$responses = fetchList($pdo, "
    SELECT id, survey_id, survey_title, submitted_at
    FROM survey_responses
    WHERE user_id = ?
    ORDER BY id DESC
", [$userId]);

$appointments = fetchList($pdo, "
    SELECT a.id, a.title, a.topic, a.notes, a.date, a.time, a.status,
           a.requested_by, a.client, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    WHERE a.user_id = ?
    ORDER BY a.date DESC, a.time DESC
", [$userId]);

$files = fetchList($pdo, "
    SELECT sf.id, sf.original_name, sf.file_size, sf.uploaded_at
    FROM user_source_files usf
    JOIN source_files sf ON sf.id = usf.file_id
    WHERE usf.user_id = ?
    ORDER BY usf.assigned_at DESC
", [$userId]);

/*
| Content this user can see: public posts plus anything targeted at their
| client. Same rule as api/user-content.php - this is the answer to "why does
| this user see that post?".
|
| The scheduling columns come along so the page can file each post on its
| calendar under live_at, exactly as the client's own dashboard calendar does.
| The post itself - caption, media, link - stays out: a super_admin may only
| read their own posts (canViewAllContent() in admin-content.php), and the
| calendar links through to admin-content.html, which applies that rule.
*/
$company = trim($user["company_name"] ?? "");

$content = fetchList($pdo, "
    SELECT id, title, platform, type_label, client,
           post_date, post_time, publish_now,
           'published' AS status, created_at,
           " . contentLiveAtSql("c") . " AS live_at
    FROM content c
    WHERE " . contentIsLiveSql("c") . "
      AND (client IS NULL OR client = '' OR (? <> '' AND client = ?))
    ORDER BY " . contentLiveAtSql("c") . " DESC
", [contentNow(), $company, $company]);

/*
| Dated phases of this client's projects, for the calendar. Drafts come too,
| marked by status - this is the team's view - except another admin's draft
| when this admin may not see every draft, the rule project-details.html
| follows.
*/
$phaseSql = "
    SELECT t.id, t.project_id, t.title, t.status, t.scheduled_date, t.scheduled_time,
           t.is_complete, p.title AS project_title
    FROM project_tasks t
    INNER JOIN projects p ON p.id = t.project_id
    WHERE p.client_id = ? AND t.scheduled_date IS NOT NULL
";
$phaseParams = [$userId];

if (!can($admin, "tasks.see_drafts", $pdo)) {
    $phaseSql .= " AND (t.status <> 'draft' OR t.created_by_admin_id = ? OR p.account_manager_admin_id = ?)";
    $phaseParams[] = (int) $admin["id"];
    $phaseParams[] = (int) $admin["id"];
}

$phases = fetchList($pdo, $phaseSql . " ORDER BY t.scheduled_date ASC, t.scheduled_time ASC, t.id ASC", $phaseParams);

/* Who works on this client is not in here: the page's Team panel loads it
   from api/client-team.php, which also decides who may change it. */

/* The client's own people, and who has been invited - separate from the staff
   in the Team panel. */
$people = array_values(array_filter(clientAccountPeople($pdo, $userId), function ($person) {
    return !empty($person["account_owner_id"]);
}));

require_once "client-team-writes.php";

respond(true, "User loaded.", [
    "user"         => $user,
    "people"       => array_map(function ($person) {
        return ["id" => (int) $person["id"], "name" => $person["name"], "email" => $person["email"], "joined_at" => $person["created_at"]];
    }, $people),
    "invites"      => clientPendingInvites($pdo, $userId),

    /* Presentation only - the POST above checks the role itself. The page uses
       these to decide whether the note and the details are fields or text. */
    "canManageNotes" => $canManageNotes,
    "canEditDetails" => $canEditDetails,

    "surveys"      => $surveys,
    "responses"    => $responses,
    "appointments" => $appointments,
    "files"        => $files,
    "content"      => $content,
    "phases"       => $phases,
]);
