<?php

/*
|--------------------------------------------------------------------------
| api/v1 - the changes an AI assistant can make, and how each is checked
|--------------------------------------------------------------------------
|
| Every change goes prepare -> the person confirms -> confirm (changes.php).
| A handler here is two functions:
|
|   prepare($pdo, $p, $args)  checks the request as this person, against
|       the portal as it is now, and returns what will happen:
|       ["args" => cleaned arguments, "summary" => one plain paragraph the
|        person approves, "preview" => the details, structured]
|       Nothing is written.
|   apply($pdo, $p, $args)    makes the change with the cleaned arguments
|       and returns its result. Run only on confirm, after prepare has
|       been run again on those same arguments - so a form someone
|       reviewed in the meantime, or a client taken off this admin, stops
|       the change. The cleaned arguments must therefore still be valid
|       input to prepare.
|
| The writes themselves are the browser endpoints' own shared functions
| (survey-writes.php, meeting-writes.php, task-writes.php,
| inquiry-templates.php, content-writes.php, setClientNote() in db.php), so
| what an assistant
| does lands exactly as the same thing done on the page: same review gate,
| same notifications, same emails.
|
| Edits (update_form, update_inquiry, update_content_draft, update_task) take
| only the fields to change, and list each change in the summary.
|
| publish_content schedules or publishes a draft post, for the roles the
| content page lets do that (canPublishContent) - nothing else here makes
| anything public on its own.
|
| The owner's Settings page is here too (settings:write, admins:write -
| scopes no other role can hold): the website address, logo and login
| slides, and the admin team - adding admins (who choose their own password
| from an emailed link; none passes through the assistant), roles, switching
| them on or off, and who manages whom. remove_login_slide is the one delete.
|
| Messages and announcements reach other people, so they go through prepare
| too: send_message (any admin, to another admin - message-writes.php) and
| send_announcement (announcements.send - announcement-writes.php, without
| an image).
|
| Deliberately missing: deleting anything else, unpublishing content, opening
| an inquiry, a client's account, and the files password. Those stay in the
| portal.
|
| Each handler is for staff, or - with "kind" => "user" - for a client acting
| on their own account (the self:* scopes). changes.php never lets one reach
| the other's.
*/

require_once __DIR__ . "/../survey-writes.php";
require_once __DIR__ . "/../meeting-writes.php";
require_once __DIR__ . "/../booking-writes.php";
require_once __DIR__ . "/../task-writes.php";
require_once __DIR__ . "/../inquiry-templates.php";
require_once __DIR__ . "/../survey-submit.php";
require_once __DIR__ . "/../content-writes.php";
require_once __DIR__ . "/../content-image-fetch.php";
require_once __DIR__ . "/../admin-settings-writes.php";
require_once __DIR__ . "/../upload-helpers.php";
require_once __DIR__ . "/../message-writes.php";
require_once __DIR__ . "/../announcement-writes.php";
require_once __DIR__ . "/../client-team-writes.php";
require_once __DIR__ . "/../team-form-writes.php";
require_once __DIR__ . "/../activity-report-lib.php";
require_once __DIR__ . "/../rate-limit.php";

const V1_CONFIRM_TTL = 900; // 15 minutes to say yes

function ensureMcpConfirmations(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    /*
    | One row per prepared change. The arguments are stored here rather than
    | sent back, so what is applied is exactly what was checked and shown -
    | the assistant can't alter it between the two steps. Kept after use as
    | the record of what an assistant changed, for whom, through which
    | connection (grant_id), with its result.
    */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mcp_confirmations (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            token_hash     CHAR(64)     NOT NULL,
            principal_kind VARCHAR(10)  NOT NULL,
            principal_id   INT          NOT NULL,
            grant_id       INT              NULL,
            tool           VARCHAR(60)  NOT NULL,
            args           TEXT         NOT NULL,
            summary        TEXT         NOT NULL,
            expires_at     INT          NOT NULL,
            used_at        INT              NULL,
            result         TEXT             NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token (token_hash),
            KEY idx_principal (principal_kind, principal_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/*
|--------------------------------------------------------------------------
| Reading arguments
|--------------------------------------------------------------------------
|
| Each throws PortalWriteError (422) naming the argument, so the assistant
| can fix the one thing and prepare again.
*/

function argInt(array $a, $key, $required = true)
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        if ($required) {
            throw new PortalWriteError("$key is required.");
        }
        return null;
    }
    if (!is_int($a[$key]) || $a[$key] <= 0) {
        throw new PortalWriteError("$key must be a positive whole number.");
    }
    return $a[$key];
}

function argText(array $a, $key, $max, $required = true)
{
    $value = $a[$key] ?? null;
    if ($value !== null && !is_string($value)) {
        throw new PortalWriteError("$key must be text.");
    }
    $value = trim((string) $value);
    if ($required && $value === "") {
        throw new PortalWriteError("$key is required.");
    }
    if (mb_strlen($value) > $max) {
        throw new PortalWriteError("$key must be $max characters or fewer (it is " . mb_strlen($value) . ").");
    }
    return $value;
}

function argEnum(array $a, $key, array $allowed, $default = null)
{
    $value = $a[$key] ?? $default;
    if (!in_array($value, $allowed, true)) {
        throw new PortalWriteError("$key must be one of: " . implode(", ", $allowed) . ".");
    }
    return $value;
}

function argBool(array $a, $key, $default)
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        return $default;
    }
    if (!is_bool($a[$key])) {
        throw new PortalWriteError("$key must be true or false.");
    }
    return $a[$key];
}

/** YYYY-MM-DD, or null when absent or "". */
function argDate(array $a, $key)
{
    $value = trim((string) ($a[$key] ?? ""));
    if ($value === "") {
        return null;
    }
    $d = DateTime::createFromFormat("!Y-m-d", $value);
    if (!$d || $d->format("Y-m-d") !== $value) {
        throw new PortalWriteError("$key must be a date like 2026-10-05.");
    }
    return $value;
}

/** HH:MM, or null when absent or "". */
function argTime(array $a, $key)
{
    $value = trim((string) ($a[$key] ?? ""));
    if ($value === "") {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
        throw new PortalWriteError("$key must be a 24-hour time like 14:30.");
    }
    return $value;
}

function argList(array $a, $key, $min, $max)
{
    $value = $a[$key] ?? null;
    if (!is_array($value) || array_values($value) !== $value) {
        throw new PortalWriteError("$key must be a list.");
    }
    if (count($value) < $min || count($value) > $max) {
        throw new PortalWriteError("$key must have between $min and $max entries.");
    }
    return $value;
}

/**
 * A post's image links: up to CONTENT_MAX_IMAGES https links, each checked
 * (contentImageTarget) but not downloaded - prepare writes nothing. null
 * when absent; [] is "no images".
 */
function argImages(array $a, $key = "images")
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        return null;
    }
    $list = $a[$key];
    if (!is_array($list) || array_values($list) !== $list) {
        throw new PortalWriteError("$key must be a list of links.");
    }
    foreach ($list as $i => $url) {
        if (!is_string($url)) {
            throw new PortalWriteError("Image " . ($i + 1) . " must be a link.");
        }
    }
    $list = array_values(array_unique(array_map("trim", $list)));
    if (count($list) > CONTENT_MAX_IMAGES) {
        throw new PortalWriteError("A post can have up to " . CONTENT_MAX_IMAGES . " images.");
    }
    foreach ($list as $i => $url) {
        contentImageTarget($i, $url);
    }
    return $list;
}

/** One https image link, checked (contentImageTarget) but not downloaded. */
function argImageLink(array $a, $key)
{
    $url = argText($a, $key, 2000);
    contentImageTarget(0, $url);
    return $url;
}

/** "2 images" */
function imageCount($n)
{
    return $n . ($n === 1 ? " image" : " images");
}

const CONTENT_PRIORITIES = ["low", "medium", "high"];
const CONTENT_ORIENTATIONS = ["horizontal", "vertical"];

/**
 * A post's team: admin ids from its client's team, plus anyone already on it
 * (allowedContentAssignees() in content-writes.php, as the page checks). null
 * when absent; [] takes everyone off.
 */
function argTeam(PDO $pdo, array $p, array $a, $company, array $current)
{
    if (!array_key_exists("team", $a) || $a["team"] === null) {
        return null;
    }
    $list = $a["team"];
    if (!is_array($list) || array_values($list) !== $list) {
        throw new PortalWriteError("team must be a list of teammate ids.");
    }
    foreach ($list as $id) {
        if (!is_int($id) || $id <= 0) {
            throw new PortalWriteError("team must be a list of teammate ids - from list_content_teammates.");
        }
    }
    $ids = array_values(array_unique($list));
    if (in_array((int) $p["id"], $ids, true)) {
        throw new PortalWriteError("Leave yourself out of team: you are on the post as the one saving it.");
    }
    $bad = array_diff($ids, allowedContentAssignees($pdo, $ids, $company, $current, $p["id"]));
    if ($bad) {
        throw new PortalWriteError(
            "Not on " . (trim((string) $company) !== "" ? trim((string) $company) . "'s" : "the") . " team: id "
            . implode(", ", $bad) . ". list_content_teammates shows who can be put on the post."
        );
    }
    return $ids;
}

/** "Ana, Sam" - admins by name, in the order given; "(nobody)" for none. */
function adminNames(PDO $pdo, array $ids)
{
    if (!$ids) {
        return "(nobody)";
    }
    $stmt = $pdo->prepare("SELECT id, name FROM admins WHERE id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")");
    $stmt->execute(array_values($ids));
    $names = array_column($stmt->fetchAll(), "name", "id");
    return implode(", ", array_map(function ($id) use ($names) {
        return $names[$id] ?? "#$id";
    }, $ids));
}

/**
 * The fields create_content_draft and update_content_draft share beyond the
 * text: orientation, priority, due date, notes and team - only the ones given. Adds each
 * to $args and, when it changes, to $changes. The team needs canAssignContent
 * (the author, the owner and the account managers).
 */
function contentTeamArgs(PDO $pdo, array $p, array $a, array &$args, array &$changes, ?array $post = null, $company = "")
{
    if (array_key_exists("orientation", $a)) {
        $args["orientation"] = argEnum($a, "orientation", CONTENT_ORIENTATIONS);
        noteChange($changes, "orientation", $post["orientation"] ?? null, $args["orientation"]);
    }
    if (array_key_exists("priority", $a)) {
        $args["priority"] = argEnum($a, "priority", CONTENT_PRIORITIES);
        noteChange($changes, "priority", $post["priority"] ?? null, $args["priority"]);
    }
    if (array_key_exists("due_date", $a)) {
        $args["due_date"] = argDate($a, "due_date");
        noteChange($changes, "due date", $post["due_date"] ?? null, $args["due_date"]);
    }
    if (array_key_exists("notes", $a)) {
        $args["notes"] = argText($a, "notes", CONTENT_NOTES_MAX, false);
        noteChange($changes, "team notes", $post ? (string) ($post["notes"] ?? "") : null, $args["notes"]);
    }
    $current = $post ? currentAssigneeIds($pdo, $post["id"]) : [];
    $team = argTeam($pdo, $p, $a, $company, $current);
    if ($team !== null) {
        if (!canAssignContent($p, $post["created_by"] ?? $p["id"])) {
            throw new PortalWriteError("Only the post's author, the Director or an account manager can change who is on it.", 403);
        }
        $args["team"] = $team;
        $was = $current;
        sort($was);
        $now = $team;
        sort($now);
        if ($was !== $now) {
            $changes[] = ["field" => "team", "from" => $post ? adminNames($pdo, $current) : null, "to" => adminNames($pdo, $team)];
        }
    }

    // The Assigned table: what each teammate does on the post. Status is
    // never set here - each person moves their own (set_my_post_status).
    if (array_key_exists("assignments", $a) && $a["assignments"] !== null) {
        if (!canAssignContent($p, $post["created_by"] ?? $p["id"])) {
            throw new PortalWriteError("Only the post's author, the Director or an account manager can change what each teammate does.", 403);
        }
        $list = $a["assignments"];
        if (!is_array($list) || array_values($list) !== $list) {
            throw new PortalWriteError("assignments must be a list of {admin_id, work_note}.");
        }
        $teamAfter = $args["team"] ?? $current;
        $notes = [];
        foreach ($list as $i => $row) {
            $id = is_array($row) ? ($row["admin_id"] ?? null) : null;
            if (!is_int($id) || $id <= 0) {
                throw new PortalWriteError("assignments item " . ($i + 1) . " needs an admin_id - one of the post's team.");
            }
            if (array_key_exists("status", $row)) {
                throw new PortalWriteError("assignments take no status: each teammate sets their own with set_my_post_status.");
            }
            if (!in_array($id, $teamAfter, true)) {
                throw new PortalWriteError("Admin $id is not on the post's team. Add them to team first - only teammates can be assigned.");
            }
            $notes[$id] = argText($row, "work_note", 2000, false);
        }
        $args["assignments"] = $notes;
        $changes[] = ["field" => "what each teammate does", "from" => null, "to" => adminNames($pdo, array_keys($notes))];
    }
}

/** What the assistant wrote as plain text, as the HTML the portal's editors store. */
function textToHtml($text)
{
    $paragraphs = preg_split("/\n\s*\n/", str_replace("\r", "", trim((string) $text)));
    return implode("", array_map(function ($para) {
        return "<p>" . nl2br(htmlspecialchars(trim($para), ENT_QUOTES, "UTF-8"), false) . "</p>";
    }, array_filter($paragraphs, function ($para) {
        return trim($para) !== "";
    })));
}

/** "Alice (Acme Ltd)" - how a client is named in a summary. */
function clientLabelFor(array $row)
{
    $company = trim((string) ($row["company_name"] ?? ""));
    return $company !== "" ? "{$row["name"]} ($company)" : (string) $row["name"];
}

function quoteFor($text, $max = 120)
{
    $text = trim(preg_replace('/\s+/', " ", (string) $text));
    return "\"" . (mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . "…" : $text) . "\"";
}

/**
 * Who a booking is with, from the tool's arguments: client (an account id the
 * admin can see) or guest_name + guest_email. Returns [bookingTarget() input,
 * label for the summary].
 */
function bookingWho(PDO $pdo, array $p, array $a)
{
    $client = argInt($a, "client", false);
    $guestName = argText($a, "guest_name", 150, false);
    $guestEmail = argText($a, "guest_email", 190, false);
    if ($client !== null && ($guestName !== "" || $guestEmail !== "")) {
        throw new PortalWriteError("Give either client or guest_name and guest_email, not both.");
    }
    if ($client !== null) {
        $account = v1VisibleClient($pdo, $p, $client);
        $company = trim((string) ($account["company_name"] ?? ""));
        return [["account" => $account, "client" => $client], $company !== "" ? $company : (string) $account["name"], false];
    }
    if ($guestName === "" || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        throw new PortalWriteError("Give client (from search_clients), or guest_name and a valid guest_email for someone without a portal account.");
    }
    return [["who" => "guest", "guest_name" => $guestName, "guest_email" => $guestEmail], "$guestName <$guestEmail> (guest)", true];
}

/** Rebuilds bookingWho()'s input from stored args, re-checking the client. */
function bookingWhoAgain(PDO $pdo, array $p, array $a)
{
    if (!empty($a["client"])) {
        return ["account" => v1VisibleClient($pdo, $p, $a["client"])];
    }
    return ["who" => "guest", "guest_name" => $a["guest_name"], "guest_email" => $a["guest_email"]];
}

/** A meeting row the admin may act on - a client's they can see, or a guest's they host. */
function loadOwnMeeting(PDO $pdo, array $p, $id)
{
    ensureAppointmentTables($pdo);
    $stmt = $pdo->prepare("
        SELECT a.*, u.name, u.company_name
        FROM appointments a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || !meetingInAdminScope($pdo, $p, $row)) {
        throw new PortalWriteError("No meeting with that id among your clients' meetings or your guests'.", 404);
    }
    return $row;
}

function meetingWhoLabel(array $row)
{
    if (!$row["user_id"]) {
        return ($row["guest_name"] ?: "a guest") . " (guest)";
    }
    return clientLabelFor($row);
}

/** A booking request the admin may manage: only one they sent. */
function loadOwnBookingRequest(PDO $pdo, array $p, $id)
{
    ensureAppointmentTables($pdo);
    $request = bookingRequestById($pdo, $id);
    if (!$request || !bookingRequestInScope($pdo, $p, $request)) {
        throw new PortalWriteError("No booking request with that id among yours.", 404);
    }
    return $request;
}

/** A meeting the admin may answer: a client's request, from one of their clients. */
function loadClientRequest(PDO $pdo, array $p, $id)
{
    // A request from one of their clients, or a time someone picked from
    // their own booking link - the meetings they see (meetingVisibilitySql()).
    [$scopeSql, $scopeParams] = meetingVisibilitySql($pdo, $p, "a");
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.date, a.time, a.status, a.topic, a.title, a.guest_name,
               COALESCE(u.name, CONCAT(COALESCE(a.guest_name, 'A guest'), ' (guest)')) AS name, u.company_name
        FROM appointments a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.id = ? AND a.requested_by = 'user' AND $scopeSql
        LIMIT 1
    ");
    $stmt->execute(array_merge([$id], $scopeParams));
    $row = $stmt->fetch();
    if (!$row) {
        throw new PortalWriteError("No meeting request from your clients with that id.", 404);
    }
    return $row;
}

/** A task with its (visible) project, ready to edit. */
function loadEditableTask(PDO $pdo, array $p, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    $project = $task ? loadScopedProject($pdo, $p, $task["project_id"]) : null;
    if (!$task || !$project) {
        throw new PortalWriteError("No task with that id on the projects you can see.", 404);
    }
    if (!canWriteTask($pdo, $p, $project)) {
        throw new PortalWriteError("You are not on this project's team.", 403);
    }
    if (!canEditTask($p, $task)) {
        throw new PortalWriteError("You can only edit your own tasks.", 403);
    }
    return [$task, $project];
}

/*
|--------------------------------------------------------------------------
| A phase's own fields and people
|--------------------------------------------------------------------------
|
| What post_project_update and update_task carry beyond the update itself,
| the same as project-task-form.html: phase, role, priority, notes (staff
| only), team (teammate ids), assignments ([{admin_id, work_note}], one row
| per person, each one a teammate) and gallery images. Only the ones given.
| People come from the project's scope (its client's team, its manager and
| its members) plus anyone already on the phase
| (task-writes.php allowedTaskPeople()), and someone else is refused at
| prepare rather than dropped at apply. Nobody's status is set here: each
| person moves their own (set_my_phase_status), and the phase completes once
| everyone assigned is done.
|
*/

/** The ids from $ids that $project's phase can't take, given who is on it now. */
function phasePeopleRefused(PDO $pdo, array $project, array $ids, array $current)
{
    return array_values(array_diff($ids, allowedTaskPeople($pdo, $project, $ids, $current)));
}

function phasePeopleError(array $project, array $bad)
{
    $company = trim((string) ($project["company_name"] ?? ""));
    return new PortalWriteError(
        "Not on " . ($company !== "" ? "$company's" : "the client's") . " team: id " . implode(", ", $bad)
        . ". list_phase_teammates shows who can go on a phase."
    );
}

/** "Nour, Sam" - who is assigned, for a summary. */
function assignmentsLabel(PDO $pdo, array $rows)
{
    return adminNames($pdo, array_column($rows, "admin_id"));
}

/**
 * Checks the phase fields in $a and adds each to $args and, when it changes,
 * to $changes. $task is the phase being edited, or null for a new one.
 */
function phaseArgs(PDO $pdo, array $a, array &$args, array &$changes, array $project, ?array $task = null)
{
    if (array_key_exists("priority", $a)) {
        $args["priority"] = argEnum($a, "priority", TASK_PRIORITIES);
        noteChange($changes, "priority", $task["priority"] ?? null, $args["priority"]);
    }
    if (array_key_exists("notes", $a)) {
        $args["notes"] = argText($a, "notes", TASK_NOTES_MAX_CHARS, false);
        noteChange($changes, "team notes", $task ? (string) ($task["notes"] ?? "") : null, $args["notes"]);
    }
    if (array_key_exists("due_date", $a)) {
        $args["due_date"] = argDate($a, "due_date");
        noteChange($changes, "due date", $task["due_date"] ?? null, $args["due_date"]);
    }

    $people = $task ? taskTeamPayload($pdo, $task["id"]) : ["teammates" => [], "assignments" => []];

    if (array_key_exists("team", $a) && $a["team"] !== null) {
        $list = $a["team"];
        if (!is_array($list) || array_values($list) !== $list) {
            throw new PortalWriteError("team must be a list of teammate ids - from list_phase_teammates.");
        }
        foreach ($list as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new PortalWriteError("team must be a list of teammate ids - from list_phase_teammates.");
            }
        }
        $ids = array_values(array_unique($list));
        $current = array_column($people["teammates"], "id");
        if ($bad = phasePeopleRefused($pdo, $project, $ids, $current)) {
            throw phasePeopleError($project, $bad);
        }
        $args["team"] = $ids;
        $before = $current;
        $after = $ids;
        sort($before);
        sort($after);
        if ($before !== $after) {
            noteChange($changes, "team", $task ? adminNames($pdo, $current) : null, adminNames($pdo, $ids));
        }
    }

    if (array_key_exists("assignments", $a) && $a["assignments"] !== null) {
        $list = $a["assignments"];
        if (!is_array($list) || array_values($list) !== $list) {
            throw new PortalWriteError("assignments must be a list of {admin_id, work_note}.");
        }
        // Who is on the phase once this change lands: assignments come from them only.
        $team = $args["team"] ?? array_column($people["teammates"], "id");
        $rows = [];
        foreach ($list as $i => $row) {
            $n = $i + 1;
            if (!is_array($row) || !is_int($row["admin_id"] ?? null) || $row["admin_id"] <= 0) {
                throw new PortalWriteError("assignments item $n needs an admin_id - one of the phase's team.");
            }
            if (array_key_exists("status", $row)) {
                throw new PortalWriteError("assignments take no status: each person sets their own with set_my_phase_status.");
            }
            if (isset($rows[$row["admin_id"]])) {
                throw new PortalWriteError("assignments lists admin $row[admin_id] twice - one row per person.");
            }
            if (!in_array($row["admin_id"], $team, true)) {
                throw new PortalWriteError("Admin $row[admin_id] is not on the phase's team. Add them to team first - only teammates can be assigned.");
            }
            $rows[$row["admin_id"]] = [
                "admin_id" => $row["admin_id"],
                "work_note" => argText($row, "work_note", 2000, false),
            ];
        }
        $rows = array_values($rows);
        $args["assignments"] = $rows;
        $was = array_map(function ($r) {
            return ["admin_id" => $r["admin_id"], "work_note" => $r["work_note"]];
        }, $people["assignments"]);
        if ($was !== $rows) {
            noteChange($changes, "assignments", $task ? assignmentsLabel($pdo, $was) : null, assignmentsLabel($pdo, $rows));
        }
    } elseif (isset($args["team"]) && $people["assignments"]) {
        // Taking someone off the team takes them off the Assigned table too.
        $leaving = array_diff(array_column($people["assignments"], "admin_id"), $args["team"]);
        if ($leaving) {
            noteChange($changes, "assignments", null, "drops " . adminNames($pdo, array_values($leaving)) . " (off the team)");
        }
    }

    // Gallery: https image links, replacing the gallery (up to five; [] clears it).
    if (array_key_exists("images", $a) && $a["images"] !== null) {
        $images = argImages($a);
        if (count($images) > TASK_GALLERY_MAX_IMAGES) {
            throw new PortalWriteError("A phase can have up to " . TASK_GALLERY_MAX_IMAGES . " gallery images.");
        }
        $args["images"] = $images;
        $had = $task ? count(taskGallery($task)) : 0;
        if ($images || $had) {
            noteChange($changes, "gallery", $had ? imageCount($had) : null,
                $images ? imageCount(count($images)) . " downloaded from the links given" : "(none)");
        }
    }
}

/** The phase columns from prepared $a, for saveProjectTask() - only those given. */
function phaseColumnsFrom(array $a)
{
    $f = [];
    foreach (["priority", "notes", "due_date"] as $key) {
        if (array_key_exists($key, $a)) {
            $f[$key] = $a[$key];
        }
    }
    return $f;
}

/**
 * Saves prepared team and assignments - the team first, since assignments
 * are drawn from it - and returns the newcomers, for notifyAddedToPhase()
 * once the transaction has committed. An assignment keeps its row, and the
 * status its person set, when the same person is listed again.
 */
function savePhasePeople(PDO $pdo, array $p, array $project, $taskId, $title, array $a)
{
    $newcomers = [];
    if (array_key_exists("team", $a)) {
        $newcomers = saveTaskTeammates($pdo, $project, $taskId, $a["team"]);
    }
    if (array_key_exists("assignments", $a)) {
        $newcomers = array_merge($newcomers, saveTaskAssignments($pdo, $project, $taskId, $a["assignments"]));
    }
    return $newcomers;
}

/**
 * Downloads prepared gallery links into a phase, replacing its gallery, and
 * removes the images it let go of. Runs after the phase row is saved.
 */
function savePhaseGallery(PDO $pdo, array $task, array $urls)
{
    $images = fetchContentImages($urls);
    try {
        $pdo->prepare("UPDATE project_tasks SET gallery_paths = ? WHERE id = ?")
            ->execute([$images ? json_encode($images, JSON_UNESCAPED_SLASHES) : null, (int) $task["id"]]);
    } catch (Throwable $e) {
        deleteFetchedContentImages($images);
        throw $e;
    }
    deleteUploadedFiles(array_diff(taskGallery($task), $images));
    return count($images);
}

/** An open invite on the caller's own account, or a refusal. */
function openTeamInvite(PDO $pdo, array $p, $inviteId)
{
    $owner = clientRow($pdo, $p);
    requireAccountOwner($owner);

    $stmt = $pdo->prepare("
        SELECT id, email FROM client_invites
        WHERE id = ? AND account_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([(int) $inviteId, $owner["account_id"]]);
    $invite = $stmt->fetch();

    if (!$invite) {
        throw new PortalWriteError("That invite is no longer open.", 404);
    }

    return $invite;
}

/** The same limit as my-team.php: each of these emails an address someone typed. */
function requireTeamInviteQuota(PDO $pdo, array $owner)
{
    if (rateLimited($pdo, "client-invite:" . (int) $owner["id"], 20, 20, 3600)) {
        throw new PortalWriteError("That's a lot of invites at once. Try again in a while.", 429);
    }
}

/** A client's own users row, for the shared writes that name them. */
function clientRow(PDO $pdo, array $p)
{
    $stmt = $pdo->prepare("SELECT id, name, email, company_name, account_owner_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$p["id"]]);
    return withClientAccount($pdo, $stmt->fetch());
}

/**
 * A client's answers, checked the way survey.html checks them before it lets
 * the form go - and stricter where the page relies on its own widgets: every
 * question answered, ticked options only from the form's own list. Returns
 * the page's answer shape, [{questionId, questionLabel, answer}], with the
 * questions' own labels, ready for submitSurveyResponse().
 */
function clientFormAnswers(PDO $pdo, array $form, array $given)
{
    $q = $pdo->prepare("
        SELECT id, question_text, question_type, required, chips
        FROM survey_questions WHERE survey_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $q->execute([$form["id"]]);
    $questions = $q->fetchAll();

    $files = array_filter($questions, function ($r) {
        return $r["question_type"] === "file";
    });
    if ($files) {
        throw new PortalWriteError(
            "This form asks for a file (" . implode(", ", array_map(function ($r) {
                return quoteFor($r["question_text"], 60);
            }, $files)) . "), and files can only be attached on the portal page: "
            . APP_URL . "/survey.html?id=" . (int) $form["id"]
        );
    }

    $byId = [];
    foreach ($given as $i => $a) {
        $qid = is_array($a) ? ($a["question_id"] ?? null) : null;
        if (!is_int($qid)) {
            throw new PortalWriteError("answers[" . ($i + 1) . "] needs a question_id.");
        }
        if (isset($byId[$qid])) {
            throw new PortalWriteError("Question $qid is answered twice.");
        }
        $byId[$qid] = array_key_exists("value", $a) ? $a["value"] : null;
    }

    $known = array_map(function ($r) {
        return (int) $r["id"];
    }, $questions);
    foreach (array_keys($byId) as $qid) {
        if (!in_array($qid, $known, true)) {
            throw new PortalWriteError("Question $qid isn't on this form.");
        }
    }

    $answers = [];
    $missing = [];
    foreach ($questions as $r) {
        $qid = (int) $r["id"];
        $value = $byId[$qid] ?? null;
        $label = (string) $r["question_text"];
        $options = json_decode((string) ($r["chips"] ?? ""), true);
        $options = is_array($options) ? array_values(array_map("strval", $options)) : [];

        if ($r["question_type"] === "checkbox") {
            if ($options) {
                // Ticked boxes, stored the way the page stores them:
                // "✅ Instagram, ✅ TikTok", in the form's own order.
                if ($value !== null && (!is_array($value) || array_filter($value, function ($v) use ($options) {
                    return !is_string($v) || !in_array($v, $options, true);
                }))) {
                    throw new PortalWriteError(quoteFor($label, 60) . " takes options from its list only: " . implode(", ", $options) . ".");
                }
                $ticked = array_values(array_filter($options, function ($o) use ($value) {
                    return is_array($value) && in_array($o, $value, true);
                }));
                $text = implode(", ", array_map(function ($o) {
                    return "✅ " . $o;
                }, $ticked));
            } else {
                // A single box - ticked or not.
                if ($value !== null && !is_bool($value)) {
                    throw new PortalWriteError(quoteFor($label, 60) . " is a single tick box: answer true or false.");
                }
                $text = $value === true ? "✅" : "";
            }
        } else {
            if ($value !== null && !is_string($value)) {
                throw new PortalWriteError(quoteFor($label, 60) . " needs a text answer.");
            }
            $text = trim((string) $value);
            $max = $r["question_type"] === "textarea" ? 10000 : 2000;
            if (mb_strlen($text) > $max) {
                throw new PortalWriteError(quoteFor($label, 60) . " must be $max characters or fewer.");
            }
        }

        if ($text === "" && (int) $r["required"] === 1) {
            $missing[] = quoteFor($label, 60);
            continue;
        }
        if ($text !== "") {
            $answers[] = ["questionId" => $qid, "questionLabel" => $label, "answer" => $text];
        }
    }

    if ($missing) {
        throw new PortalWriteError("Still to answer: " . implode("; ", $missing) . ".");
    }

    return $answers;
}

/** Records a change for an edit's summary, when the value actually changes. */
function noteChange(array &$changes, $field, $from, $to)
{
    if ((string) $from !== (string) $to) {
        $changes[] = ["field" => $field, "from" => $from, "to" => $to];
    }
}

/** 'title "Old" → "New"; date (empty) → "2026-10-05"' - an edit's changes, for its summary. */
function describeChanges(array $changes)
{
    return implode("; ", array_map(function ($c) {
        return "{$c["field"]} " . ($c["from"] === null || $c["from"] === "" ? "(empty)" : quoteFor($c["from"], 60))
            . " → " . ($c["to"] === null || $c["to"] === "" ? "(empty)" : quoteFor($c["to"], 60));
    }, $changes));
}

/** A form's questions as given - [{text, type, options, max_file_size_mb}], checked the way the builder checks them. */
function formQuestionsArg(array $a)
{
    $questions = [];
    foreach (argList($a, "questions", 1, 50) as $i => $q) {
        $n = $i + 1;
        if (!is_array($q)) {
            throw new PortalWriteError("questions[$n] must be an object.");
        }
        $type = argEnum($q, "type", SURVEY_QUESTION_TYPES, "input");
        $options = [];
        if (isset($q["options"])) {
            foreach (argList($q, "options", 0, 20) as $o) {
                if (!is_string($o) || trim($o) === "" || mb_strlen($o) > 100) {
                    throw new PortalWriteError("questions[$n].options must be short, non-empty texts.");
                }
                $options[] = trim($o);
            }
        }
        if ($type === "checkbox" && count($options) < 2) {
            throw new PortalWriteError("questions[$n] is a checkbox question: give it at least two options.");
        }
        if ($type !== "checkbox" && $options) {
            throw new PortalWriteError("questions[$n]: only checkbox questions take options.");
        }
        $maxMb = null;
        if (isset($q["max_file_size_mb"])) {
            if ($type !== "file" || !is_int($q["max_file_size_mb"]) || $q["max_file_size_mb"] < 1 || $q["max_file_size_mb"] > 50) {
                throw new PortalWriteError("questions[$n].max_file_size_mb is for file questions, 1 to 50.");
            }
            $maxMb = $q["max_file_size_mb"];
        }
        $questions[] = [
            "text" => argText($q, "text", 1000),
            "type" => $type,
            "options" => $options,
            "max_file_size_mb" => $maxMb,
        ];
    }
    return $questions;
}

/** Those questions as writeSurveyQuestions() takes them. */
function surveyQuestionRows(array $questions)
{
    return array_map(function ($q) {
        return ["text" => $q["text"], "type" => $q["type"], "chips" => $q["options"], "maxFileSizeMb" => $q["max_file_size_mb"]];
    }, $questions);
}

/** An inquiry's questions as given - [{label, type, required}]. */
function inquiryFieldsArg(array $a)
{
    $fields = [];
    foreach (argList($a, "fields", 1, 30) as $i => $f) {
        if (!is_array($f)) {
            throw new PortalWriteError("fields[" . ($i + 1) . "] must be an object.");
        }
        $fields[] = [
            "label" => argText($f, "label", 200),
            "type" => argEnum($f, "type", INQUIRY_FIELD_TYPES, "input"),
            "required" => argBool($f, "required", true),
        ];
    }
    return $fields;
}

/** The content types by the ids list_content_types gives them: ["videos" => "Videos", ...]. */
function contentTypesById()
{
    $types = [];
    foreach (CONTENT_TYPE_TITLES as $label) {
        $types[trim(preg_replace("/[^a-z0-9]+/", "_", strtolower($label)), "_")] = $label;
    }
    return $types;
}

/** A form this admin may see, with its client, as update_form edits it. */
function loadEditableForm(PDO $pdo, array $p, $id)
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.title, s.description, s.status, s.assigned_user_id, s.created_by_admin_id,
               u.name, u.company_name
        FROM surveys s
        JOIN users u ON u.id = s.assigned_user_id
        WHERE s.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $form = $stmt->fetch();
    if (!$form || (!v1SeesAllForms($p) && (int) $form["created_by_admin_id"] !== (int) $p["id"])) {
        throw new PortalWriteError("No form with that id among the forms you can see.", 404);
    }
    if (!in_array($form["status"], SURVEY_EDITABLE_STATUSES, true)) {
        throw new PortalWriteError("That form can't be changed any more (it is {$form["status"]}).", 409);
    }
    if (!isUserInScope($pdo, $p["role"], $p["id"], $form["assigned_user_id"])) {
        throw new PortalWriteError("That form's client is no longer one of yours.", 403);
    }

    $q = $pdo->prepare("
        SELECT question_text, question_type, chips, max_file_size_mb
        FROM survey_questions WHERE survey_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $q->execute([$id]);
    $form["questions"] = array_map(function ($r) {
        $options = json_decode((string) ($r["chips"] ?? ""), true);
        return [
            "text" => (string) $r["question_text"],
            "type" => (string) $r["question_type"],
            "options" => is_array($options) ? array_values(array_map("strval", $options)) : [],
            "max_file_size_mb" => $r["max_file_size_mb"] === null ? null : (int) $r["max_file_size_mb"],
        ];
    }, $q->fetchAll());
    return $form;
}

/** An inquiry with its questions, and whether answers have locked them. */
function loadEditableInquiry(PDO $pdo, $id)
{
    ensureInquiryTables($pdo);
    $stmt = $pdo->prepare("
        SELECT id, title, intro_text, slug, status, account_manager_admin_id, reference
        FROM inquiries WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $inquiry = $stmt->fetch();
    if (!$inquiry) {
        throw new PortalWriteError("No inquiry with that id.", 404);
    }
    $f = $pdo->prepare("
        SELECT field_label, field_type, required
        FROM inquiry_fields WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $f->execute([$id]);
    $inquiry["fields"] = array_map(function ($r) {
        return ["label" => (string) $r["field_label"], "type" => (string) $r["field_type"], "required" => (int) $r["required"] === 1];
    }, $f->fetchAll());
    $inquiry["locked"] = inquiryHasAnswers($pdo, $id);
    return $inquiry;
}

/** A draft post this admin may edit (admin-content.php canEditContent()). */
function loadEditableDraft(PDO $pdo, array $p, $id)
{
    ensureContentColumns($pdo);
    $stmt = $pdo->prepare("SELECT c.*, " . contentStatusSql("c") . " AS live_status FROM content c WHERE c.id = ? LIMIT 1");
    $stmt->execute([contentNow(), $id]);
    $post = $stmt->fetch();
    if (!$post || !canEditContent($p, $post["created_by"], isContentAssignee($pdo, $id, $p["id"]))) {
        throw new PortalWriteError("No content with that id among the posts you can edit.", 404);
    }
    if ($post["live_status"] !== "draft") {
        throw new PortalWriteError(
            "That post is {$post["live_status"]}, and only drafts can be changed from here. Edit it in the portal: "
            . APP_URL . "/admin-content-form.html?id=" . (int) $id,
            409
        );
    }
    return $post;
}

/*
|--------------------------------------------------------------------------
| The handlers
|--------------------------------------------------------------------------
*/

function v1WriteHandlers()
{
    return [

        // ── Clients ──────────────────────────────────────────────────────

        "save_my_client_note" => [
            "scope" => "clients:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $text = argText($a, "text", 5000, false);
                $client = v1VisibleClient($pdo, $p, $clientId);
                $before = getClientNote($pdo, $clientId, $p["id"]);
                return [
                    "args" => ["client" => $clientId, "text" => $text],
                    "summary" => $text === ""
                        ? "Clear your private note about " . clientLabelFor($client) . "."
                        : "Replace your private note about " . clientLabelFor($client) . " with " . quoteFor($text, 200)
                          . ". Only you can see it.",
                    "preview" => [
                        "client" => v1ClientRef($client["id"], $client["name"], $client["company_name"]),
                        "untrusted_content" => ["note_now" => $before, "note_after" => $text],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                if (!setClientNote($pdo, $a["client"], $p["id"], $a["text"])) {
                    throw new PortalWriteError("Could not save the note.", 500);
                }
                $who = eventClientName($pdo, $a["client"]);
                logEvent($pdo, EVENT_CLIENT_NOTE_SAVED, "user", $a["client"], $who,
                    ($a["text"] === "" ? "Cleared their private note about " : "Saved their private note about ") . $who, $a["client"]);
                return ["client_id" => $a["client"], "saved" => true];
            },
        ],

        // ── Forms ────────────────────────────────────────────────────────

        "create_form_draft" => [
            "scope" => "forms:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $title = argText($a, "title", 255);
                $description = argText($a, "description", 5000, false);
                $questions = formQuestionsArg($a);
                $client = v1VisibleClient($pdo, $p, $clientId);
                return [
                    "args" => ["client" => $clientId, "title" => $title, "description" => $description, "questions" => $questions],
                    "summary" => "Create the form " . quoteFor($title) . " for " . clientLabelFor($client)
                        . " with " . count($questions) . (count($questions) === 1 ? " question" : " questions")
                        . ". It goes into the review queue: the client sees it only after a reviewer approves it.",
                    "preview" => [
                        "client" => v1ClientRef($client["id"], $client["name"], $client["company_name"]),
                        "title" => $title,
                        "description" => $description,
                        "questions" => $questions,
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureSurveyColumns($pdo);
                ensureNotificationsTable($pdo);
                $created = createSurveyForm($pdo, $p, $a["title"], $a["description"], $a["client"], surveyQuestionRows($a["questions"]), true);
                return ["form_id" => $created["id"], "status" => $created["status"]];
            },
        ],

        "update_form" => [
            "scope" => "forms:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $form = loadEditableForm($pdo, $p, $id);
                $args = ["id" => $id];
                $changes = [];
                if (array_key_exists("title", $a)) {
                    $args["title"] = argText($a, "title", 255);
                    noteChange($changes, "title", $form["title"], $args["title"]);
                }
                if (array_key_exists("description", $a)) {
                    $args["description"] = argText($a, "description", 5000, false);
                    noteChange($changes, "description", v1PlainText($form["description"] ?? ""), $args["description"]);
                }
                $newQuestions = false;
                if (array_key_exists("questions", $a)) {
                    $args["questions"] = formQuestionsArg($a);
                    $newQuestions = $args["questions"] !== $form["questions"];
                }
                if (!$changes && !$newQuestions) {
                    throw new PortalWriteError("Nothing to change - every value given is what the form already has.");
                }
                $parts = $changes ? [describeChanges($changes)] : [];
                if ($newQuestions) {
                    $parts[] = "its questions replaced with " . count($args["questions"])
                        . (count($args["questions"]) === 1 ? " question" : " questions");
                }
                return [
                    "args" => $args,
                    "summary" => "Change the form " . quoteFor($form["title"]) . " for " . clientLabelFor($form) . ": "
                        . implode("; ", $parts) . ". "
                        . ($form["status"] === "pending"
                            ? "It is taken back from the client and goes into the review queue: they see it again only after a reviewer approves it."
                            : ($form["status"] === "rejected"
                                ? "It goes back into the review queue: the client sees it only after a reviewer approves it."
                                : "It stays in the review queue: the client sees it only after a reviewer approves it.")),
                    "preview" => [
                        "form" => ["id" => (int) $form["id"], "title" => (string) $form["title"]],
                        "client" => v1ClientRef($form["assigned_user_id"], $form["name"], $form["company_name"]),
                        "status_now" => (string) $form["status"],
                        "status_after" => "pending_review",
                        "untrusted_content" => ["changes" => $changes, "questions" => $newQuestions ? $args["questions"] : null],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureSurveyColumns($pdo);
                ensureNotificationsTable($pdo);
                $form = loadEditableForm($pdo, $p, $a["id"]);
                $updated = updateSurveyForm(
                    $pdo,
                    $p,
                    $a["id"],
                    $a["title"] ?? $form["title"],
                    array_key_exists("description", $a) ? $a["description"] : (string) $form["description"],
                    $form["assigned_user_id"],
                    surveyQuestionRows($a["questions"] ?? $form["questions"]),
                    function ($survey) use ($p) {
                        return v1SeesAllForms($p) || (int) $survey["created_by_admin_id"] === (int) $p["id"];
                    },
                    true
                );
                return ["form_id" => $updated["id"], "status" => $updated["status"]];
            },
        ],

        "review_form" => [
            "scope" => "forms:review",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $decision = argEnum($a, "decision", ["approve", "return"]);
                $comment = argText($a, "comment", 1000, $decision === "return");
                $stmt = $pdo->prepare("
                    SELECT s.id, s.title, s.status, u.name, u.company_name, creator.name AS author
                    FROM surveys s
                    JOIN users u ON u.id = s.assigned_user_id
                    LEFT JOIN admins creator ON creator.id = s.created_by_admin_id
                    WHERE s.id = ? LIMIT 1
                ");
                $stmt->execute([$id]);
                $form = $stmt->fetch();
                if (!$form) {
                    throw new PortalWriteError("No form with that id.", 404);
                }
                if ($form["status"] !== "pending_review") {
                    throw new PortalWriteError("That form isn't waiting for review (it is {$form["status"]}).", 409);
                }
                return [
                    "args" => ["id" => $id, "decision" => $decision, "comment" => $comment],
                    "summary" => $decision === "approve"
                        ? "Approve the form " . quoteFor($form["title"]) . ". It is released to " . clientLabelFor($form)
                          . " at once, and they and its author are notified."
                        : "Return the form " . quoteFor($form["title"]) . " to its author" . ($form["author"] ? " " . $form["author"] : "")
                          . " with the comment " . quoteFor($comment, 300) . ". The client never sees it.",
                    "preview" => [
                        "form" => ["id" => (int) $form["id"], "title" => (string) $form["title"]],
                        "client" => clientLabelFor($form),
                        "decision" => $decision,
                        "comment" => $comment,
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                reviewSurveyForm($pdo, $p, $a["id"], $a["decision"] === "approve" ? "approve" : "reject", $a["comment"]);
                return ["form_id" => $a["id"], "status" => $a["decision"] === "approve" ? "pending" : "rejected"];
            },
        ],

        // ── Inquiries ────────────────────────────────────────────────────

        "create_inquiry_draft" => [
            "scope" => "inquiries:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $title = argText($a, "title", 200);
                $intro = argText($a, "intro", 2000, false);
                $fields = inquiryFieldsArg($a);
                return [
                    "args" => ["title" => $title, "intro" => $intro, "fields" => $fields],
                    "summary" => "Create the consultation inquiry " . quoteFor($title) . " with " . count($fields)
                        . (count($fields) === 1 ? " question" : " questions")
                        . ". It is created closed: nobody can answer it until it is opened on the Inquiries page.",
                    "preview" => ["title" => $title, "intro" => $intro, "fields" => $fields],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureInquiryTables($pdo);
                $created = createInquiry($pdo, $p, $a["title"], $a["intro"], $a["fields"], "inactive", null, null);
                return ["inquiry_id" => $created["id"], "name" => $created["slug"], "status" => "inactive"];
            },
        ],

        "update_inquiry" => [
            "scope" => "inquiries:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $inquiry = loadEditableInquiry($pdo, $id);
                $args = ["id" => $id];
                $changes = [];
                if (array_key_exists("title", $a)) {
                    $args["title"] = argText($a, "title", 200);
                    noteChange($changes, "title", $inquiry["title"], $args["title"]);
                }
                if (array_key_exists("intro", $a)) {
                    $args["intro"] = argText($a, "intro", 2000, false);
                    noteChange($changes, "intro", v1PlainText($inquiry["intro_text"] ?? ""), $args["intro"]);
                }
                $newFields = false;
                if (array_key_exists("fields", $a)) {
                    $args["fields"] = inquiryFieldsArg($a);
                    $newFields = $args["fields"] !== $inquiry["fields"];
                }
                $rewrites = $changes || $newFields;
                if ($rewrites && $inquiry["locked"]) {
                    throw new PortalWriteError(
                        "This inquiry already has answers, so its title, intro and questions are locked. "
                        . "Its reference can still change, and it can still be closed.",
                        409
                    );
                }
                if (!$rewrites) {
                    // Nothing in the questions changes, so they are not rewritten.
                    unset($args["title"], $args["intro"], $args["fields"]);
                }
                if (array_key_exists("reference", $a)) {
                    $args["reference"] = argText($a, "reference", 100, false);
                    noteChange($changes, "reference", (string) ($inquiry["reference"] ?? ""), $args["reference"]);
                }
                $closing = false;
                if (array_key_exists("close", $a)) {
                    if (argBool($a, "close", false) !== true) {
                        throw new PortalWriteError("close can only be true: an inquiry is opened on the portal's Inquiries page.");
                    }
                    $args["close"] = true;
                    $closing = $inquiry["status"] !== "inactive";
                    noteChange($changes, "status", $inquiry["status"] === "inactive" ? "closed" : "open", "closed");
                }
                if (!$changes && !$newFields) {
                    throw new PortalWriteError("Nothing to change - every value given is what the inquiry already has.");
                }
                $parts = $changes ? [describeChanges($changes)] : [];
                if ($newFields) {
                    $parts[] = "its questions replaced with " . count($args["fields"])
                        . (count($args["fields"]) === 1 ? " question" : " questions");
                }
                // A new title moves the name in the public link (inquiry.html?name=...).
                $newName = isset($args["title"]) && $args["title"] !== $inquiry["title"]
                    ? slugifyInquiryTitle($pdo, $args["title"], $id)
                    : null;
                if ($newName === $inquiry["slug"]) {
                    $newName = null;
                }
                return [
                    "args" => $args,
                    "summary" => "Change the consultation inquiry " . quoteFor($inquiry["title"]) . ": " . implode("; ", $parts) . "."
                        . ($newName !== null
                            ? " Its public link becomes " . APP_URL . "/inquiry.html?name=$newName, so links already handed out stop working."
                            : "")
                        . ($closing ? " It is closed: nobody can answer it until it is opened again on the Inquiries page." : ""),
                    "preview" => [
                        "inquiry" => ["id" => (int) $inquiry["id"], "title" => (string) $inquiry["title"]],
                        "name_after" => $newName ?? v1NullableString($inquiry["slug"]),
                        "status_after" => $closing || $inquiry["status"] === "inactive" ? "inactive" : "active",
                        "untrusted_content" => ["changes" => $changes, "fields" => $newFields ? $args["fields"] : null],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $inquiry = loadEditableInquiry($pdo, $a["id"]);
                $status = !empty($a["close"]) ? "inactive" : normaliseStatus($inquiry["status"]);
                $managerId = $inquiry["account_manager_admin_id"] === null ? null : (int) $inquiry["account_manager_admin_id"];
                $reference = array_key_exists("reference", $a) ? $a["reference"] : $inquiry["reference"];
                if (isset($a["title"]) || isset($a["intro"]) || isset($a["fields"])) {
                    $saved = updateInquiry(
                        $pdo,
                        $a["id"],
                        $a["title"] ?? $inquiry["title"],
                        $a["intro"] ?? (string) $inquiry["intro_text"],
                        $a["fields"] ?? $inquiry["fields"],
                        $status,
                        $managerId,
                        $reference
                    );
                    $name = $saved["slug"];
                } else {
                    saveInquirySettings($pdo, $a["id"], $status, $managerId, $reference);
                    $name = $inquiry["slug"];
                }
                return ["inquiry_id" => (int) $a["id"], "name" => v1NullableString($name), "status" => $status];
            },
        ],

        // ── Content ──────────────────────────────────────────────────────

        "create_content_draft" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $types = contentTypesById();
                $type = argEnum($a, "type", array_keys($types));
                $title = argText($a, "title", 255);
                // The page's own limit, counted on the text (admin-content.php).
                $caption = argText($a, "caption", 10000, false);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($time !== null && $date === null) {
                    throw new PortalWriteError("A time needs a date.");
                }
                $link = normalisePortalLink($a["link"] ?? "");
                $images = argImages($a) ?? [];
                $client = v1VisibleClient($pdo, $p, $clientId);
                $company = trim((string) ($client["company_name"] ?? ""));
                if ($company === "") {
                    throw new PortalWriteError("Posts are tied to a client by company name, and " . $client["name"] . " has none yet.");
                }
                $args = ["client" => $clientId, "company" => $company, "type" => $type, "type_label" => $types[$type],
                         "title" => $title, "caption" => $caption, "date" => $date, "time" => $time, "link" => $link,
                         "images" => $images];
                $extras = [];
                contentTeamArgs($pdo, $p, $a, $args, $extras, null, $company);
                return [
                    "args" => $args,
                    "summary" => "Save a draft " . $types[$type] . " post " . quoteFor($title) . " for $company"
                        . ($date ? ", dated $date" . ($time ? " $time" : "") : "")
                        . ($images ? ", with " . imageCount(count($images)) . " downloaded from the links given" : "")
                        . ($extras ? "; " . implode("; ", array_map(function ($c) {
                            return "{$c["field"]} " . quoteFor($c["to"], 60);
                        }, $extras)) : "")
                        . (!empty($args["team"]) ? " (each teammate is notified)" : "")
                        . ". It stays a draft until someone schedules it (publish_content or the portal).",
                    "preview" => [
                        "client" => $company, "type" => $types[$type], "title" => $title,
                        "date" => $date, "time" => $time, "link" => $link,
                        "orientation" => $args["orientation"] ?? "horizontal",
                        "priority" => $args["priority"] ?? "medium",
                        "due_date" => $args["due_date"] ?? null,
                        "team" => isset($args["team"]) ? adminNames($pdo, $args["team"]) : null,
                        "untrusted_content" => ["caption" => $caption, "images" => $images, "notes" => $args["notes"] ?? null],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureContentColumns($pdo);
                // Downloaded only now, on confirm; removed again if the post isn't saved.
                $images = fetchContentImages($a["images"] ?? []);
                try {
                    // The columns admin-content-form.html fills for a type: its
                    // name as label, platform and category; never published here.
                    $pdo->prepare("
                        INSERT INTO content
                            (title, client, link, caption, content_type, type_label, platform, category,
                             orientation, media_path, media_paths, post_date, post_time, publish_now, status,
                             priority, due_date, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'draft', ?, ?, ?, ?)
                    ")->execute([
                        $a["title"], $a["company"], $a["link"], $a["caption"] !== "" ? textToHtml($a["caption"]) : null,
                        $a["type"], $a["type_label"], $a["type_label"], $a["type_label"],
                        $a["orientation"] ?? "horizontal",
                        $images[0] ?? null, $images ? json_encode($images, JSON_UNESCAPED_SLASHES) : null,
                        $a["date"], $a["time"] !== null ? $a["time"] . ":00" : null,
                        $a["priority"] ?? "medium", $a["due_date"] ?? null,
                        ($a["notes"] ?? "") !== "" ? $a["notes"] : null, $p["id"],
                    ]);
                } catch (Throwable $e) {
                    deleteFetchedContentImages($images);
                    throw $e;
                }
                $id = (int) $pdo->lastInsertId();
                [, $eventClientId] = eventContent($pdo, $id);
                logEvent($pdo, EVENT_CONTENT_CREATED, "content", $id, $a["title"],
                    "Created post \"" . $a["title"] . "\" for " . $a["company"] . " (draft)", $eventClientId);
                // As the page does: the post first, then its team, who are notified.
                saveContentAssignees($pdo, $id, [], $a["team"] ?? [], $a["title"], $p["id"]);
                if (!empty($a["assignments"])) {
                    saveContentWorkNotes($pdo, $id, $a["assignments"]);
                }
                return ["content_id" => $id, "status" => "draft", "images" => count($images),
                        "team" => count($a["team"] ?? [])];
            },
        ],

        "update_content_draft" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $post = loadEditableDraft($pdo, $p, $id);
                $types = contentTypesById();
                $args = ["id" => $id];
                $changes = [];
                if (array_key_exists("title", $a)) {
                    $args["title"] = argText($a, "title", 255);
                    noteChange($changes, "title", $post["title"], $args["title"]);
                }
                if (array_key_exists("caption", $a)) {
                    $args["caption"] = argText($a, "caption", 10000, false);
                    noteChange($changes, "caption", v1PlainText($post["caption"] ?? ""), $args["caption"]);
                }
                if (array_key_exists("type", $a)) {
                    $args["type"] = argEnum($a, "type", array_keys($types));
                    noteChange($changes, "type", $types[$post["content_type"]] ?? $post["content_type"], $types[$args["type"]]);
                }
                if (array_key_exists("date", $a)) {
                    $args["date"] = argDate($a, "date");
                    noteChange($changes, "date", $post["post_date"], $args["date"]);
                }
                if (array_key_exists("time", $a)) {
                    $args["time"] = argTime($a, "time");
                    noteChange($changes, "time", $post["post_time"] === null ? null : substr($post["post_time"], 0, 5), $args["time"]);
                }
                if (array_key_exists("link", $a)) {
                    $args["link"] = normalisePortalLink($a["link"]);
                    noteChange($changes, "link", $post["link"], $args["link"]);
                }
                // Links replace every image the post has; [] removes them.
                $images = argImages($a);
                if ($images !== null) {
                    $args["images"] = $images;
                    $had = count(contentImages($post));
                    if ($images || $had) {
                        $changes[] = ["field" => "images", "from" => $had ? imageCount($had) : null,
                                      "to" => $images ? imageCount(count($images)) . " downloaded from the links given" : null];
                    }
                }
                $date = array_key_exists("date", $args) ? $args["date"] : $post["post_date"];
                $time = array_key_exists("time", $args) ? $args["time"] : $post["post_time"];
                if ($time !== null && $time !== "" && ($date === null || $date === "")) {
                    throw new PortalWriteError("A time needs a date.");
                }
                $company = trim((string) ($post["client"] ?? ""));
                contentTeamArgs($pdo, $p, $a, $args, $changes, $post, $company);
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - every value given is what the post already has.");
                }
                $joining = isset($args["team"]) ? array_values(array_diff($args["team"], currentAssigneeIds($pdo, $id))) : [];
                return [
                    "args" => $args,
                    "summary" => "Change the draft post " . quoteFor($post["title"]) . ($company !== "" ? " for $company" : "") . ": "
                        . describeChanges($changes)
                        . ($joining ? " (" . adminNames($pdo, $joining) . (count($joining) === 1 ? " is" : " are") . " notified)" : "")
                        . ". It stays a draft until someone schedules it (publish_content or the portal).",
                    "preview" => [
                        "content_id" => $id,
                        "client" => $company !== "" ? $company : null,
                        "untrusted_content" => ["changes" => $changes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $post = loadEditableDraft($pdo, $p, $a["id"]);
                $label = isset($a["type"]) ? contentTypesById()[$a["type"]] : null;
                // Downloaded only now, on confirm; removed again if the post isn't saved.
                $hadImages = contentImages($post);
                $images = array_key_exists("images", $a) ? fetchContentImages($a["images"]) : $hadImages;
                // Only the given fields; status and schedule flag stay as they
                // are. Still a draft, or nothing.
                try {
                    $pdo->prepare("
                        UPDATE content
                        SET title = ?, caption = ?, content_type = ?, type_label = ?, platform = ?, category = ?,
                            post_date = ?, post_time = ?, link = ?, media_path = ?, media_paths = ?,
                            orientation = ?, priority = ?, due_date = ?, notes = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND status = 'draft'
                    ")->execute([
                        $a["title"] ?? $post["title"],
                        array_key_exists("caption", $a) ? ($a["caption"] !== "" ? textToHtml($a["caption"]) : null) : $post["caption"],
                        $a["type"] ?? $post["content_type"],
                        $label ?? $post["type_label"],
                        $label ?? $post["platform"],
                        $label ?? $post["category"],
                        array_key_exists("date", $a) ? $a["date"] : $post["post_date"],
                        array_key_exists("time", $a) ? ($a["time"] !== null ? $a["time"] . ":00" : null) : $post["post_time"],
                        array_key_exists("link", $a) ? $a["link"] : $post["link"],
                        array_key_exists("images", $a) ? ($images[0] ?? null) : $post["media_path"],
                        array_key_exists("images", $a) ? ($images ? json_encode($images, JSON_UNESCAPED_SLASHES) : null) : $post["media_paths"],
                        $a["orientation"] ?? $post["orientation"],
                        $a["priority"] ?? $post["priority"],
                        array_key_exists("due_date", $a) ? $a["due_date"] : ($post["due_date"] ?? null),
                        array_key_exists("notes", $a) ? ($a["notes"] !== "" ? $a["notes"] : null) : $post["notes"],
                        $a["id"],
                    ]);
                } catch (Throwable $e) {
                    if (array_key_exists("images", $a)) {
                        deleteFetchedContentImages($images);
                    }
                    throw $e;
                }
                [$eventTitle, $eventClientId] = eventContent($pdo, $a["id"]);
                logEvent($pdo, EVENT_CONTENT_UPDATED, "content", $a["id"], $eventTitle,
                    "Edited draft \"" . $eventTitle . "\"", $eventClientId);
                // The old files go only once the post no longer points at them,
                // as admin-content.php does.
                if (array_key_exists("images", $a)) {
                    deleteFetchedContentImages(array_diff($hadImages, $images));
                }
                // The team last, as the page does; the people new to it are notified.
                if (array_key_exists("team", $a)) {
                    saveContentAssignees($pdo, $a["id"], currentAssigneeIds($pdo, $a["id"]), $a["team"],
                        $a["title"] ?? $post["title"], $p["id"]);
                }
                if (array_key_exists("assignments", $a)) {
                    saveContentWorkNotes($pdo, $a["id"], $a["assignments"]);
                }
                return ["content_id" => (int) $a["id"], "status" => "draft", "images" => count($images)];
            },
        ],

        "set_my_post_status" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $status = argEnum($a, "status", array_keys(TASK_ASSIGNMENT_STATUSES));
                ensureContentColumns($pdo);
                $stmt = $pdo->prepare("
                    SELECT c.title, c.client, ca.status
                    FROM content_assignees ca
                    JOIN content c ON c.id = ca.content_id
                    WHERE ca.content_id = ? AND ca.admin_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$id, (int) $p["id"]]);
                $mine = $stmt->fetch();
                if (!$mine) {
                    throw new PortalWriteError("You aren't on a post with that id - get_dashboard shows yours.", 403);
                }
                if ((string) $mine["status"] === $status) {
                    throw new PortalWriteError("Nothing to change - your status on that post is already " . strtolower(TASK_ASSIGNMENT_STATUSES[$status]) . ".", 409);
                }
                return [
                    "args" => ["id" => $id, "status" => $status],
                    "summary" => "Set your status on the post " . quoteFor($mine["title"])
                        . (trim((string) $mine["client"]) !== "" ? " for " . trim((string) $mine["client"]) : "")
                        . " to " . strtolower(TASK_ASSIGNMENT_STATUSES[$status]) . ". Only your own row changes; the team sees it.",
                    "preview" => ["post_id" => $id, "status" => $status, "untrusted_content" => ["title" => (string) $mine["title"]]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureContentColumns($pdo);
                return ["post_id" => $a["id"], "status" => setOwnContentStatus($pdo, $a["id"], $p["id"], $a["status"])];
            },
        ],

        "set_my_post_done" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $done = argBool($a, "done", null);
                if ($done === null) {
                    throw new PortalWriteError("done is required.");
                }
                $stmt = $pdo->prepare("
                    SELECT c.title, c.client, ca.is_done
                    FROM content_assignees ca
                    JOIN content c ON c.id = ca.content_id
                    WHERE ca.content_id = ? AND ca.admin_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$id, (int) $p["id"]]);
                $mine = $stmt->fetch();
                if (!$mine) {
                    throw new PortalWriteError("You aren't on a post with that id - get_dashboard shows yours.", 403);
                }
                if (((int) $mine["is_done"] === 1) === $done) {
                    throw new PortalWriteError("Nothing to change - your box on that post is already " . ($done ? "ticked." : "unticked."), 409);
                }
                return [
                    "args" => ["id" => $id, "done" => $done],
                    "summary" => ($done ? "Tick" : "Untick") . " your box on the post " . quoteFor($mine["title"])
                        . (trim((string) $mine["client"]) !== "" ? " for " . trim((string) $mine["client"]) : "")
                        . ". Only your own box changes; the team sees it.",
                    "preview" => ["post_id" => $id, "done" => $done, "untrusted_content" => ["title" => (string) $mine["title"]]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureContentColumns($pdo);
                return ["post_id" => $a["id"], "done" => setOwnContentDone($pdo, $a["id"], $p["id"], $a["done"]) === 1];
            },
        ],

        "publish_content" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                if (!canPublishContent($p)) {
                    throw new PortalWriteError("Only the Director and the account managers schedule and publish posts.", 403);
                }
                $id = argInt($a, "id");
                $post = loadEditableDraft($pdo, $p, $id);
                $now = argBool($a, "publish_now", false);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($now && ($date !== null || $time !== null)) {
                    throw new PortalWriteError("Give publish_now, or a date and time - not both.");
                }
                if (!$now && ($date === null || $time === null)) {
                    throw new PortalWriteError("Give a date and time to schedule it, or publish_now: true.");
                }
                $company = trim((string) ($post["client"] ?? ""));
                if ($company === "") {
                    throw new PortalWriteError("Pick a client for the post before scheduling or publishing it.");
                }
                // The page's own rules: a time already passed goes live now.
                [$status] = resolveContentSchedule("scheduled", $now, $date, $time);
                return [
                    "args" => ["id" => $id, "publish_now" => $now, "date" => $date, "time" => $time],
                    "summary" => ($status === "published"
                            ? "Publish the post " . quoteFor($post["title"]) . " for $company now"
                                . ($now ? "" : " (its time, $date $time, has already passed, so it goes live straight away and keeps that date)")
                                . ". $company is notified in the portal."
                            : "Schedule the post " . quoteFor($post["title"]) . " for $company to go live on $date at $time"
                                . ". It goes live on its own then, and $company is notified when it does.")
                        . " Once it is out, it is changed or taken down in the portal.",
                    "preview" => [
                        "content_id" => $id, "client" => $company, "title" => $post["title"],
                        "status_after" => $status, "date" => $now ? null : $date, "time" => $now ? null : $time,
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $post = loadEditableDraft($pdo, $p, $a["id"]);
                [$status, $date, $time] = resolveContentSchedule("scheduled", $a["publish_now"], $a["date"], $a["time"]);
                $stmt = $pdo->prepare("
                    UPDATE content
                    SET status = ?, publish_now = ?, post_date = ?, post_time = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = 'draft'
                ");
                $stmt->execute([$status, $a["publish_now"] ? 1 : 0, $date, $time !== null ? $time . ":00" : null, $a["id"]]);
                if ($stmt->rowCount() !== 1) {
                    throw new PortalWriteError("That post is no longer a draft.", 409);
                }
                [, $eventClientId] = eventContent($pdo, $a["id"]);
                logEvent($pdo, EVENT_CONTENT_STATUS, "content", $a["id"], $post["title"],
                    ($status === "published" ? "Published \"" : "Scheduled \"") . $post["title"] . "\""
                    . ($status === "published" ? "" : " for " . $date . ($time !== null ? " at " . $time : "")), $eventClientId);
                // As admin-content.php: only a post that is live has anything to announce.
                if ($status === "published") {
                    notifyUsersAtCompany($pdo, $post["client"], NOTIFY_CONTENT_PUBLISHED, "New content published",
                        $post["title"], "content.html?id=" . (int) $a["id"], (int) $p["id"]);
                }
                return ["content_id" => (int) $a["id"], "status" => $status];
            },
        ],

        // ── Calendar ─────────────────────────────────────────────────────

        "propose_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($date === null || $time === null) {
                    throw new PortalWriteError("date and time are required.");
                }
                if ($date < date("Y-m-d")) {
                    throw new PortalWriteError("That date has passed.");
                }
                $topic = argText($a, "topic", 200);
                $notes = argText($a, "notes", 2000, false);
                $client = v1VisibleClient($pdo, $p, $clientId);
                $company = trim((string) ($client["company_name"] ?? ""));
                // Like the page: the request goes to every account at the
                // company that this admin can reach.
                $targets = $company !== ""
                    ? meetingTargetsForCompany($pdo, $p, $company)
                    : [["id" => $client["id"], "name" => $client["name"], "email" => $client["email"], "company_name" => null]];
                return [
                    "args" => ["client" => $clientId, "company" => $company, "date" => $date, "time" => $time, "topic" => $topic, "notes" => $notes],
                    "summary" => "Ask " . ($company !== "" ? $company : $client["name"]) . " for a meeting on $date at $time about " . quoteFor($topic)
                        . ". " . implode(", ", array_column($targets, "name"))
                        . (count($targets) === 1 ? " is" : " are") . " notified in the portal and by email, and can accept or decline.",
                    "preview" => [
                        "recipients" => array_map(function ($t) {
                            return v1ClientRef($t["id"], $t["name"], $t["company_name"] ?? null);
                        }, $targets),
                        "date" => $date, "time" => $time,
                        "untrusted_content" => ["topic" => $topic, "notes" => $notes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                ensureNotificationsTable($pdo);
                $targets = $a["company"] !== ""
                    ? meetingTargetsForCompany($pdo, $p, $a["company"])
                    : [v1VisibleClient($pdo, $p, $a["client"])];
                $ids = requestMeetingFromClients($pdo, $p, $targets, $a["company"], $a["date"], $a["time"], $a["topic"], $a["notes"]);
                return ["meeting_ids" => $ids];
            },
        ],

        "respond_to_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $decision = argEnum($a, "decision", ["accept", "decline"]);
                $request = loadClientRequest($pdo, $p, $id);
                if ($request["status"] !== "pending") {
                    throw new PortalWriteError("That request has already been answered ({$request["status"]}).", 409);
                }
                $topic = (string) ($request["topic"] ?? $request["title"] ?? "");
                return [
                    "args" => ["id" => $id, "decision" => $decision],
                    "summary" => ($decision === "accept" ? "Confirm " : "Decline ") . clientLabelFor($request)
                        . "'s meeting request for {$request["date"]} at " . substr((string) $request["time"], 0, 5)
                        . " about " . quoteFor($topic) . ". They are notified.",
                    "preview" => [
                        "meeting_id" => $id,
                        "client" => clientLabelFor($request),
                        "date" => (string) $request["date"],
                        "time" => substr((string) $request["time"], 0, 5),
                        "decision" => $decision,
                        "untrusted_content" => ["topic" => $topic],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                answerClientMeetingRequest($pdo, $p, $a["id"], $a["decision"] === "accept" ? "approved" : "rejected", true);
                return ["meeting_id" => $a["id"], "status" => $a["decision"] === "accept" ? "approved" : "rejected"];
            },
        ],

        "cancel_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $reason = argText($a, "reason", 500, false);
                $row = loadOwnMeeting($pdo, $p, $id);
                if (!in_array($row["status"], ["pending", "approved"], true)) {
                    throw new PortalWriteError($row["status"] === "cancelled" ? "That meeting is already cancelled." : "A declined meeting has nothing to cancel.", 409);
                }
                $isRequest = $row["status"] === "pending";
                $topic = (string) ($row["topic"] ?? $row["title"] ?? "");
                return [
                    "args" => ["id" => $id, "reason" => $reason],
                    "summary" => ($isRequest ? "Cancel the meeting request " : "Cancel the meeting ") . quoteFor($topic) . " with " . meetingWhoLabel($row)
                        . " on {$row["date"]} at " . substr((string) $row["time"], 0, 5) . ". They are told by email"
                        . ($row["user_id"] ? " and in the portal" : "") . ($isRequest ? "." : ", with a cancellation for their calendar.") . " This can't be undone.",
                    "preview" => [
                        "meeting_id" => $id, "status" => (string) $row["status"],
                        "date" => (string) $row["date"], "time" => substr((string) $row["time"], 0, 5),
                        "untrusted_content" => ["topic" => $topic, "reason" => $reason],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                cancelMeeting($pdo, $p, $a["id"], $a["reason"]);
                return ["meeting_id" => $a["id"], "status" => "cancelled"];
            },
        ],

        "add_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                [$who, $label] = bookingWho($pdo, $p, $a);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($date === null || $time === null) {
                    throw new PortalWriteError("date and time are required.");
                }
                $topic = argText($a, "topic", 200);
                $notes = argText($a, "notes", 2000, false);
                $allowClash = argBool($a, "allow_clash", false);
                $avail = loadAvailability($pdo, (int) $p["id"]);
                if ($date . " " . $time < availabilityNow($avail)->format("Y-m-d H:i")) {
                    throw new PortalWriteError("That time has passed (times are in " . $avail["timezone"] . ").");
                }
                $warnings = meetingTimeWarnings($pdo, (int) $p["id"], $date, $time, (int) $avail["slot_minutes"]);
                if ($warnings && !$allowClash) {
                    throw new PortalWriteError(implode(" ", $warnings) . " Pick another time, or set allow_clash to true to add it anyway.", 409);
                }
                return [
                    "args" => array_merge(["client" => null, "guest_name" => null, "guest_email" => null], $who, ["account" => null,
                        "date" => $date, "time" => $time, "topic" => $topic, "notes" => $notes, "allow_clash" => $allowClash]),
                    "summary" => "Add a confirmed meeting with $label on $date at $time ({$avail["slot_minutes"]} min, {$avail["timezone"]}) about " . quoteFor($topic)
                        . ". They get an email with a calendar invite" . (empty($who["client"]) ? "" : ", and see it in the portal") . "."
                        . ($warnings ? " Note: " . implode(" ", $warnings) : ""),
                    "preview" => [
                        "date" => $date, "time" => $time, "duration_min" => (int) $avail["slot_minutes"], "timezone" => $avail["timezone"],
                        "warnings" => $warnings,
                        "untrusted_content" => ["topic" => $topic, "notes" => $notes, "with" => $label],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                $input = array_merge(bookingWhoAgain($pdo, $p, $a), ["topic" => $a["topic"], "notes" => $a["notes"], "date" => $a["date"], "time" => $a["time"]]);
                $ids = addMeetingDirectly($pdo, $p, $input, true);
                return ["meeting_ids" => $ids, "status" => "approved"];
            },
        ],

        "send_booking_request" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                [$who, $label, $guest] = bookingWho($pdo, $p, $a);
                $topic = argText($a, "topic", 200);
                $notes = argText($a, "notes", 2000, false);
                $days = argInt($a, "window_days", false) ?? 14;
                if (!in_array($days, BOOKING_WINDOWS, true)) {
                    throw new PortalWriteError("window_days must be one of: " . implode(", ", BOOKING_WINDOWS) . ".");
                }
                $avail = loadAvailability($pdo, (int) $p["id"]);
                $from = availabilityNow($avail)->format("Y-m-d");
                $to = availabilityNow($avail)->modify("+" . min($days, (int) $avail["horizon_days"]) . " days")->format("Y-m-d");
                $count = count(openSlots($pdo, (int) $p["id"], $from, $to, null, $avail));
                if (!$count) {
                    throw new PortalWriteError("You have no open times in the next $days days. Add hours on the Appointments page (Availability), or give them longer.", 409);
                }
                return [
                    "args" => array_merge(["client" => null, "guest_name" => null, "guest_email" => null], $who, ["account" => null,
                        "topic" => $topic, "notes" => $notes, "window_days" => $days]),
                    "summary" => "Send $label a link to pick a time for " . quoteFor($topic) . " by $to: $count open times of {$avail["slot_minutes"]} min ({$avail["timezone"]})."
                        . ($guest ? " The link is emailed to them." : " Everyone on the account gets their own link by email, and the request in the portal.")
                        . " You then confirm or reject the time they pick.",
                    "preview" => [
                        "window_to" => $to, "open_times" => $count, "duration_min" => (int) $avail["slot_minutes"], "timezone" => $avail["timezone"],
                        "untrusted_content" => ["topic" => $topic, "notes" => $notes, "with" => $label],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                $input = array_merge(bookingWhoAgain($pdo, $p, $a), ["topic" => $a["topic"], "notes" => $a["notes"], "window_days" => $a["window_days"]]);
                $made = createBookingRequest($pdo, $p, $input);
                return ["booking_request_id" => $made["id"], "sent_to" => $made["sent_to"], "open_times" => $made["slots"], "link" => $made["link"]];
            },
        ],

        "withdraw_booking_request" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $request = loadOwnBookingRequest($pdo, $p, $id);
                $state = bookingRequestState($pdo, $request);
                if ($state === "picked") {
                    throw new PortalWriteError("A time was already picked. Confirm or decline that meeting (respond_to_meeting) instead.", 409);
                }
                if ($state !== "open" && $state !== "expired") {
                    throw new PortalWriteError("That booking request is already closed ($state).", 409);
                }
                return [
                    "args" => ["id" => $id],
                    "summary" => "Withdraw the booking request " . quoteFor($request["topic"]) . " for " . ($request["client"] ?: $request["guest_name"] . " (guest)")
                        . ". Its links stop working; nobody is emailed.",
                    "preview" => ["booking_request_id" => $id, "state" => $state, "untrusted_content" => ["topic" => (string) $request["topic"]]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                cancelBookingRequest($pdo, $p, $a["id"]);
                return ["booking_request_id" => $a["id"], "state" => "cancelled"];
            },
        ],

        "resend_booking_request" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $request = loadOwnBookingRequest($pdo, $p, $id);
                if (bookingRequestState($pdo, $request) !== "open") {
                    throw new PortalWriteError("Only an open booking request can be sent again.", 409);
                }
                return [
                    "args" => ["id" => $id],
                    "summary" => "Email the booking link for " . quoteFor($request["topic"]) . " again to " . ($request["client"] ?: $request["guest_name"] . " (guest)")
                        . ", with a new link. The old link stops working.",
                    "preview" => ["booking_request_id" => $id, "untrusted_content" => ["topic" => (string) $request["topic"]]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $link = renewBookingLinks($pdo, $p, $a["id"], true);
                return ["booking_request_id" => $a["id"], "link" => $link];
            },
        ],

        // ── Messages and announcements ───────────────────────────────────

        "send_message" => [
            "scope" => "messages:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $to = argInt($a, "to");
                $body = argText($a, "body", MESSAGE_MAX_CHARS);
                ensureAdminColumns($pdo);
                ensureAdminMessageTables($pdo);
                [$body, $other] = checkMessage($pdo, $p["id"], $to, $body);
                return [
                    "args" => ["to" => $to, "body" => $body],
                    "summary" => "Send " . $other["name"] . " a direct message from you: " . quoteFor($body)
                        . ". They see it in Messages.",
                    "preview" => [
                        "to" => ["id" => $other["id"], "name" => $other["name"], "role" => $other["role"]],
                        "untrusted_content" => ["body" => $body],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $row = sendMessage($pdo, $p["id"], $a["to"], $a["body"]);
                return ["message_id" => (int) $row["id"], "to" => $a["to"]];
            },
        ],

        "send_announcement" => [
            "scope" => "announcements:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                if (!can($p, "announcements.send", $pdo)) {
                    throw new PortalWriteError("You do not have permission to send announcements.", 403);
                }
                $audience = argEnum($a, "audience", ["team", "client"]);
                $scope = argEnum($a, "scope", ["all", "specific"], "all");
                $posted = $scope === "specific" ? argList($a, "recipients", 1, 500) : [];
                foreach ($posted as $id) {
                    if (!is_int($id) || $id <= 0) {
                        throw new PortalWriteError("recipients must be a list of ids.");
                    }
                }
                $title = argText($a, "title", ANNOUNCEMENT_TITLE_MAX);
                $description = argText($a, "description", ANNOUNCEMENT_BODY_MAX, false);
                $eventDate = argDate($a, "event_date");

                ensureAnnouncementsTable($pdo);
                [$title, $description, $eventDate] = checkAnnouncement($audience, $scope, $title, $description, $eventDate ?? "");
                $ids = announcementRecipientIds($pdo, $p["id"], $audience, $scope, $posted);

                // Names for the person to check, the first few of a long list.
                $marks = implode(",", array_fill(0, min(count($ids), 10), "?"));
                $stmt = $pdo->prepare("SELECT name FROM " . ($audience === "team" ? "admins" : "users")
                    . " WHERE id IN ($marks) ORDER BY name");
                $stmt->execute(array_slice($ids, 0, 10));
                $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $who = $scope === "all"
                    ? ($audience === "team" ? "the whole team" : "every client") . " (" . count($ids) . ")"
                    : implode(", ", $names) . (count($ids) > 10 ? " and " . (count($ids) - 10) . " more" : "");

                return [
                    "args" => ["audience" => $audience, "scope" => $scope, "recipients" => $ids,
                               "title" => $title, "description" => $description, "event_date" => $eventDate],
                    "summary" => "Send the " . ($audience === "team" ? "team" : "client") . " announcement " . quoteFor($title)
                        . ($eventDate !== null ? " (dated $eventDate)" : "") . " to $who. Each of them gets a notification.",
                    "preview" => [
                        "audience" => $audience,
                        "recipient_count" => count($ids),
                        "event_date" => $eventDate,
                        "untrusted_content" => ["title" => $title, "description" => $description],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureAnnouncementsTable($pdo);
                // Prepare ran again on confirm, so the list is today's.
                $ids = announcementRecipientIds($pdo, $p["id"], $a["audience"], "specific", $a["recipients"]);
                $id = createAnnouncement($pdo, $p["id"], $a["audience"], $ids,
                    $a["title"], $a["description"], $a["event_date"]);
                return ["announcement_id" => $id, "sent" => count($ids)];
            },
        ],

        // ── Team forms ───────────────────────────────────────────────────

        "send_team_form" => [
            "scope" => "team_forms:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                if (!can($p, "team_forms.send", $pdo)) {
                    throw new PortalWriteError("You do not have permission to send team forms.", 403);
                }
                $scope = argEnum($a, "scope", TEAM_FORM_SCOPES, "all");
                $roles = $scope === "all" || !isset($a["roles"]) ? [] : checkTeamFormRoles(argList($a, "roles", 0, 4));
                $posted = $scope === "all" || !isset($a["recipients"]) ? [] : argList($a, "recipients", 0, 500);
                foreach ($posted as $rid) {
                    if (!is_int($rid) || $rid <= 0) {
                        throw new PortalWriteError("recipients must be a list of admin ids.");
                    }
                }
                $questions = array_map(function ($q) {
                    return ["text" => $q["text"], "type" => $q["type"], "chips" => $q["options"], "maxFileSizeMb" => $q["max_file_size_mb"]];
                }, formQuestionsArg($a));
                $due = argDate($a, "due_date");

                ensureTeamFormTables($pdo);
                [$title, $description, $due, $clean] = checkTeamForm(
                    argText($a, "title", 200), argText($a, "description", 5000, false), $due ?? "", $questions
                );
                $ids = teamFormRecipientIds($pdo, $p["id"], $scope, $roles, $posted);

                $marks = implode(",", array_fill(0, min(count($ids), 10), "?"));
                $stmt = $pdo->prepare("SELECT name FROM admins WHERE id IN ($marks) ORDER BY name");
                $stmt->execute(array_slice($ids, 0, 10));
                $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $who = $scope === "all"
                    ? "the whole team (" . count($ids) . ")"
                    : implode(", ", $names) . (count($ids) > 10 ? " and " . (count($ids) - 10) . " more" : "");

                return [
                    "args" => ["scope" => $scope, "roles" => $roles, "recipients" => $posted, "title" => $title,
                               "description" => $description, "due_date" => $due, "questions" => $a["questions"]],
                    "summary" => "Send the team form " . quoteFor($title) . " (" . count($clean)
                        . (count($clean) === 1 ? " question" : " questions") . ($due ? ", due $due" : "") . ") to $who."
                        . " Each of them gets a notification and can answer once.",
                    "preview" => [
                        "recipient_count" => count($ids),
                        "roles" => $roles,
                        "due_date" => $due,
                        "untrusted_content" => ["title" => $title, "description" => $description,
                            "questions" => array_column($clean, "text")],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureTeamFormTables($pdo);
                $questions = array_map(function ($q) {
                    return ["text" => $q["text"], "type" => $q["type"], "chips" => $q["options"], "maxFileSizeMb" => $q["max_file_size_mb"]];
                }, formQuestionsArg($a));
                [$title, $description, $due, $clean] = checkTeamForm($a["title"], $a["description"], $a["due_date"] ?? "", $questions);
                $ids = teamFormRecipientIds($pdo, $p["id"], $a["scope"], $a["roles"], $a["recipients"]);
                $id = createTeamForm($pdo, $p, $title, $description, $due, $clean, $ids, $a["roles"]);
                return ["team_form_id" => $id, "sent" => count($ids)];
            },
        ],

        "answer_team_form" => [
            "scope" => "team_forms:answer",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureTeamFormTables($pdo);
                $id = argInt($a, "id");
                $form = teamFormFor($pdo, $p, $id);
                if (!$form || !$form["mine"]) {
                    throw new PortalWriteError("No team form with that id was sent to you.", 404);
                }
                if ($form["mine"]["status"] === "submitted") {
                    throw new PortalWriteError("You have already sent your answers to this form.", 409);
                }
                if ($form["status"] !== "open") {
                    throw new PortalWriteError("This form is closed and no longer takes answers.", 409);
                }

                $given = argList($a, "answers", 0, 100);
                $byId = [];
                foreach ($given as $i => $g) {
                    if (!is_array($g) || !isset($g["question_id"]) || !is_int($g["question_id"])) {
                        throw new PortalWriteError("answers[" . ($i + 1) . "] needs a question_id.");
                    }
                    $value = $g["answer"] ?? "";
                    if (is_array($value)) {
                        $value = implode(", ", array_map("strval", $value));
                    }
                    if (!is_string($value)) {
                        throw new PortalWriteError("answers[" . ($i + 1) . "].answer must be text, or a list of ticked options.");
                    }
                    $byId[$g["question_id"]] = trim($value);
                }

                $lines = [];
                foreach ($form["questions"] as $q) {
                    if ($q["type"] === "file") {
                        if ($q["required"]) {
                            throw new PortalWriteError("This form asks for a file upload, which can only be done in the portal.", 422);
                        }
                        continue;
                    }
                    $v = $byId[$q["id"]] ?? "";
                    if ($q["required"] && $v === "") {
                        throw new PortalWriteError("Answer question " . $q["id"] . ": " . mb_substr($q["text"], 0, 80), 422);
                    }
                    $lines[] = ["question" => $q["text"], "answer" => $v];
                }

                return [
                    "args" => ["id" => $id, "answers" => $given],
                    "summary" => "Send your answers to the team form " . quoteFor($form["title"]) . ". Once sent they can't be changed, and "
                        . ($form["sender"]["name"] ?: "whoever sent it") . " is told.",
                    "preview" => ["form_id" => $id, "untrusted_content" => ["answers" => $lines]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $byId = [];
                foreach ($a["answers"] as $g) {
                    $v = $g["answer"] ?? "";
                    $byId[$g["question_id"]] = is_array($v) ? implode(", ", array_map("strval", $v)) : (string) $v;
                }
                submitTeamFormResponse($pdo, $p, $a["id"], $byId, null);
                return ["team_form_id" => $a["id"], "status" => "submitted"];
            },
        ],

        "close_team_form" => [
            "scope" => "team_forms:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureTeamFormTables($pdo);
                $id = argInt($a, "id");
                $reopen = argBool($a, "reopen", false);
                $form = teamFormFor($pdo, $p, $id);
                if (!$form) {
                    throw new PortalWriteError("No team form with that id that you may see.", 404);
                }
                if (!$form["can_close"]) {
                    throw new PortalWriteError("Only whoever sent this form, or the Director, can close or reopen it.", 403);
                }
                if ($form["status"] === ($reopen ? "open" : "closed")) {
                    throw new PortalWriteError("It is already " . ($reopen ? "open" : "closed") . ".", 409);
                }
                $left = isset($form["progress"]) ? $form["progress"]["total"] - $form["progress"]["answered"] : 0;
                return [
                    "args" => ["id" => $id, "reopen" => $reopen],
                    "summary" => ($reopen ? "Reopen" : "Close") . " the team form " . quoteFor($form["title"]) . "."
                        . (!$reopen && $left ? " $left " . ($left === 1 ? "person who hasn't" : "people who haven't") . " answered won't be able to." : ""),
                    "preview" => ["team_form_id" => $id, "status" => $reopen ? "open" : "closed"],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                setTeamFormOpen($pdo, $p, $a["id"], $a["reopen"]);
                return ["team_form_id" => $a["id"], "status" => $a["reopen"] ? "open" : "closed"];
            },
        ],

        // ── Activity reports (activity-report-lib.php) ─────────────────────────
        // Planning, filling in and closing your own report, and reviewing the
        // ones sent to you. Planning for others, templates and deleting stay
        // in the portal.

        "plan_my_activity_report" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                if (!can($p, "reports.self", $pdo)) {
                    throw new PortalWriteError("Planning your own report needs \"Plan their own activity report\" (Settings > Permissions).", 403);
                }
                $reviewers = drCheckReviewers($pdo, array_map(function ($x) {
                    if (!is_int($x) || $x <= 0) {
                        throw new PortalWriteError("reviewers must be a list of admin ids.");
                    }
                    return $x;
                }, argList($a, "reviewers", 1, 20)), (int) $p["id"]);
                $acts = [];
                foreach (argList($a, "activities", 1, DR_MAX_ACTIVITIES) as $i => $x) {
                    if (!is_array($x)) {
                        throw new PortalWriteError("activities[$i] must be an object.");
                    }
                    $acts[] = ["title" => argText($x, "title", 200), "description" => argText($x, "description", 2000, false),
                        "count_unit" => argText($x, "count_unit", 30, false) ?: null];
                }
                $stmt = $pdo->prepare("SELECT 1 FROM daily_reports WHERE admin_id = ? AND report_date = ?");
                $stmt->execute([(int) $p["id"], drToday($pdo)]);
                if ($stmt->fetchColumn()) {
                    throw new PortalWriteError("You already have a report for today. Use add_report_activity to add to it.", 409);
                }
                $names = array_map(function ($id) use ($pdo) { return eventAdminName($pdo, $id); }, $reviewers);
                return [
                    "args" => ["reviewers" => $reviewers, "activities" => $acts],
                    "summary" => "Start your own activity report for today with " . count($acts) . " activit" . (count($acts) === 1 ? "y" : "ies")
                        . " (" . implode(", ", array_map(function ($x) { return quoteFor($x["title"], 60); }, $acts)) . "), reviewed by "
                        . implode(", ", $names) . (count($names) > 1 ? " - any one of them can review it." : "."),
                    "preview" => ["reviewers" => $reviewers, "activities" => count($acts)],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                [$ids] = drPlan($pdo, $p, ["self" => true, "reviewer_ids" => $a["reviewers"], "activities" => $a["activities"]]);
                return ["report_id" => $ids[0], "status" => "planned"];
            },
        ],

        "add_report_activity" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $reportId = argInt($a, "report");
                $r = drFind($pdo, $reportId, false);
                if (!$r || $r["admin_id"] !== (int) $p["id"]) {
                    throw new PortalWriteError("No activity report of yours with that id.", 404);
                }
                if (!drCanAddOwn($pdo, $p, $r, drToday($pdo))) {
                    throw new PortalWriteError($r["created_by"] === (int) $p["id"]
                        ? "This is your own plan: change it on the plan page."
                        : "You can't add activities to this report: it needs to be today's, still open, and you need \"Plan their own activity report\".", 403);
                }
                $act = ["title" => argText($a, "title", 200), "description" => argText($a, "description", 2000, false),
                    "count_unit" => argText($a, "count_unit", 30, false)];
                return [
                    "args" => ["report" => $reportId] + $act,
                    "summary" => "Add " . quoteFor($act["title"]) . " to your activity report for " . $r["report_date"]
                        . ", marked as added by you. " . $r["created_by_name"] . "'s plan stays as it is.",
                    "preview" => ["report_id" => $reportId, "title" => $act["title"]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drAddOwnActivity($pdo, $p, $a["report"], $a);
                return ["report_id" => $a["report"], "added" => $a["title"]];
            },
        ],

        "remove_report_activity" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $reportId = argInt($a, "report");
                $activityId = argInt($a, "activity");
                $r = drFind($pdo, $reportId, false);
                if (!$r || $r["admin_id"] !== (int) $p["id"]) {
                    throw new PortalWriteError("No activity report of yours with that id.", 404);
                }
                $act = null;
                foreach ($r["activities"] as $x) {
                    if ($x["id"] === $activityId) {
                        $act = $x;
                    }
                }
                if (!$act) {
                    throw new PortalWriteError("That activity isn't on this report.", 404);
                }
                if ($act["origin"] !== "employee") {
                    throw new PortalWriteError("Only activities you added can be removed.", 403);
                }
                if (!drCanAddOwn($pdo, $p, $r, drToday($pdo))) {
                    throw new PortalWriteError("This report can't be changed any more.", 403);
                }
                if (drTouched($act)) {
                    throw new PortalWriteError(quoteFor($act["title"]) . " has been worked on, so it can't be removed.");
                }
                return [
                    "args" => ["report" => $reportId, "activity" => $activityId],
                    "summary" => "Remove " . quoteFor($act["title"]) . " from your activity report for " . $r["report_date"] . ".",
                    "preview" => ["report_id" => $reportId, "activity_id" => $activityId],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drRemoveOwnActivity($pdo, $p, $a["report"], $a["activity"]);
                return ["report_id" => $a["report"], "removed" => $a["activity"]];
            },
        ],

        "save_end_of_day" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $reportId = argInt($a, "report");
                $r = drFind($pdo, $reportId, false);
                if (!$r || $r["admin_id"] !== (int) $p["id"]) {
                    throw new PortalWriteError("No activity report of yours with that id.", 404);
                }
                if (!drCanFill($p, $r, drToday($pdo))) {
                    throw new PortalWriteError($r["status"] === "planned" ? "This day has passed, so the report can't change." : "It's closed, so this can't change.");
                }
                // What's given replaces what's saved; the rest stays.
                $eod = drCleanEndOfDay([
                    "outstanding" => array_key_exists("outstanding", $a) ? argText($a, "outstanding", 5000, false) : $r["outstanding"],
                    "priorities" => array_key_exists("priorities", $a) ? argText($a, "priorities", 5000, false) : $r["priorities"],
                    "challenges" => array_key_exists("challenges", $a) ? argText($a, "challenges", 5000, false) : $r["challenges"],
                    "challenge_priority" => array_key_exists("challenge_priority", $a)
                        ? argEnum($a, "challenge_priority", DR_CHALLENGE_PRIORITIES) : $r["challenge_priority"],
                ]);
                if ($eod["outstanding"] === $r["outstanding"] && $eod["priorities"] === $r["priorities"]
                    && $eod["challenges"] === $r["challenges"] && $eod["challenge_priority"] === $r["challenge_priority"]) {
                    throw new PortalWriteError("That's how it is already.", 409);
                }
                $parts = [];
                foreach (["outstanding" => "outstanding work", "priorities" => "tomorrow's priorities", "challenges" => "challenges"] as $k => $label) {
                    if ($eod[$k] !== $r[$k]) {
                        $parts[] = $eod[$k] === "" ? "clear " . $label : $label . " " . quoteFor($eod[$k], 120);
                    }
                }
                if ($eod["challenge_priority"] !== $r["challenge_priority"] && $eod["challenge_priority"]) {
                    $parts[] = "priority " . $eod["challenge_priority"];
                }
                return [
                    "args" => ["report" => $reportId] + $eod,
                    "summary" => "On your activity report for " . $r["report_date"] . ", set the end of day: " . implode("; ", $parts) . ".",
                    "preview" => ["report_id" => $reportId, "challenge_priority" => $eod["challenge_priority"]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drSaveEndOfDay($pdo, $p, $a["report"], $a);
                return ["report_id" => $a["report"], "saved" => true];
            },
        ],

        "update_report_activity" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $reportId = argInt($a, "report");
                $activityId = argInt($a, "activity");
                $r = drFind($pdo, $reportId);
                if (!$r || $r["admin_id"] !== (int) $p["id"]) {
                    throw new PortalWriteError("No activity report of yours with that id.", 404);
                }
                $act = null;
                foreach ($r["activities"] as $x) {
                    if ($x["id"] === $activityId) {
                        $act = $x;
                    }
                }
                if (!$act) {
                    throw new PortalWriteError("That activity isn't on this report.", 404);
                }
                // Only what's given changes; the rest stays as saved.
                $status = array_key_exists("status", $a) && $a["status"] !== null
                    ? argEnum($a, "status", DR_ACTIVITY_STATUSES) : $act["status"];
                $outcome = array_key_exists("outcome", $a) && $a["outcome"] !== null ? argText($a, "outcome", 5000, false) : $act["outcome"];
                $count = $act["count_value"];
                if (array_key_exists("count", $a) && $a["count"] !== null) {
                    if (!is_int($a["count"]) || $a["count"] < 0) {
                        throw new PortalWriteError("count must be a whole number, 0 or more.");
                    }
                    if (!$act["count_unit"]) {
                        throw new PortalWriteError("Nothing is counted on this activity.");
                    }
                    $count = $a["count"];
                }
                if ($status === $act["status"] && $outcome === $act["outcome"] && $count === $act["count_value"]) {
                    throw new PortalWriteError("That's how it is already.", 409);
                }
                // The page's own checks: an open report, or work sent back; never a reviewed activity.
                drCheckActivityEdits($pdo, $p, $reportId, [["id" => $activityId, "status" => $status, "outcome" => $outcome, "count_value" => $count]]);

                $labels = ["todo" => "To do", "in_progress" => "In progress", "follow_up" => "Follow up", "done" => "Done"];
                $parts = [];
                if ($status !== $act["status"]) {
                    $parts[] = "status to " . $labels[$status];
                }
                if ($outcome !== $act["outcome"]) {
                    $parts[] = $outcome === "" ? "clear the outcome" : "outcome " . quoteFor($outcome, 200);
                }
                if ($count !== $act["count_value"]) {
                    $parts[] = "count to " . $count . " " . $act["count_unit"];
                }
                return [
                    "args" => ["report" => $reportId, "activity" => $activityId, "status" => $status, "outcome" => $outcome,
                        "count" => $act["count_unit"] ? $count : null],
                    "summary" => "On your activity report for " . $r["report_date"] . ", set " . quoteFor($act["title"]) . ": " . implode(", ", $parts) . "."
                        . ($status === "done" && $r["reviewer_name"] ? " " . $r["reviewer_name"] . " can review it once it's Done." : ""),
                    "preview" => ["report_id" => $reportId, "activity_id" => $activityId, "status" => $status],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drUpdateActivities($pdo, $p, $a["report"], [["id" => $a["activity"], "status" => $a["status"],
                    "outcome" => $a["outcome"], "count_value" => $a["count"]]]);
                return ["report_id" => $a["report"], "activity_id" => $a["activity"], "status" => $a["status"]];
            },
        ],

        "close_activity_report" => [
            "scope" => "activity_reports:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $id = argInt($a, "id");
                $r = drFind($pdo, $id, false);
                if (!$r || $r["admin_id"] !== (int) $p["id"]) {
                    throw new PortalWriteError("No activity report of yours with that id.", 404);
                }
                if ($r["status"] !== "planned") {
                    throw new PortalWriteError("It's already closed.", 409);
                }
                if (!drCanFill($p, $r, drToday($pdo))) {
                    throw new PortalWriteError("This day has passed, so the report can't change.");
                }
                $open = count(array_filter($r["activities"], function ($x) { return $x["status"] !== "done"; }));
                // The end-of-day note: what's given replaces what's saved; the rest stays.
                $eod = [
                    "outstanding" => array_key_exists("outstanding", $a) ? argText($a, "outstanding", 5000, false) : $r["outstanding"],
                    "priorities" => array_key_exists("priorities", $a) ? argText($a, "priorities", 5000, false) : $r["priorities"],
                    "challenges" => array_key_exists("challenges", $a) ? argText($a, "challenges", 5000, false) : $r["challenges"],
                    "challenge_priority" => array_key_exists("challenge_priority", $a)
                        ? argEnum($a, "challenge_priority", DR_CHALLENGE_PRIORITIES) : $r["challenge_priority"],
                ];
                $eod = drCleanEndOfDay($eod);
                return [
                    "args" => ["id" => $id] + $eod,
                    "summary" => "Close your activity report for " . $r["report_date"] . " (" . drProgressText($r) . ") and send it to "
                        . ($r["reviewer_name"] ?: "your reviewer") . ". After that only activities sent back to you can change."
                        . ($open ? " " . $open . " activit" . ($open === 1 ? "y isn't" : "ies aren't") . " Done." : ""),
                    "preview" => ["report_id" => $id, "status" => "sent"],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drClose($pdo, $p, $a["id"], $a);
                return ["report_id" => $a["id"], "status" => "sent"];
            },
        ],

        "review_report_activity" => [
            "scope" => "activity_reports:review",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $activityId = argInt($a, "activity");
                $state = argEnum($a, "decision", ["approved", "changes"]);
                $feedback = argText($a, "feedback", 2000, $state === "changes");
                $stmt = $pdo->prepare("SELECT report_id FROM daily_report_activities WHERE id = ?");
                $stmt->execute([$activityId]);
                $r = drFind($pdo, (int) $stmt->fetchColumn(), false);
                if (!$r || !drCanSee($pdo, $p, $r)) {
                    throw new PortalWriteError("No activity with that id that you may see.", 404);
                }
                if (!drCanReviewActivities($pdo, $p, $r)) {
                    throw new PortalWriteError("You can't review this report.", 403);
                }
                $act = null;
                foreach ($r["activities"] as $x) {
                    if ($x["id"] === $activityId) {
                        $act = $x;
                    }
                }
                if (!$act || !drAwaiting($act)) {
                    throw new PortalWriteError("Only activities saved as Done and not reviewed yet can be reviewed.", 409);
                }
                return [
                    "args" => ["activity" => $activityId, "decision" => $state, "feedback" => $feedback],
                    "summary" => ($state === "changes"
                        ? "Send " . quoteFor($act["title"]) . " back to " . $r["admin_name"] . " with: " . quoteFor($feedback, 300) . ". It reopens for them."
                        : "Mark " . quoteFor($act["title"]) . " on " . $r["admin_name"] . "'s report for " . $r["report_date"] . " as reviewed"
                            . ($feedback !== "" ? ", with: " . quoteFor($feedback, 300) : "") . ". It locks for them.")
                        . " They're notified.",
                    "preview" => ["report_id" => $r["id"], "activity_id" => $activityId, "decision" => $state],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                [$r] = drReviewActivity($pdo, $p, $a["activity"], $a["decision"], $a["feedback"]);
                return ["report_id" => $r["id"], "activity_id" => $a["activity"], "decision" => $a["decision"]];
            },
        ],

        "review_activity_report" => [
            "scope" => "activity_reports:review",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureDailyReportTables($pdo);
                $id = argInt($a, "id");
                $note = argText($a, "note", 2000, false);
                $r = drFind($pdo, $id, false);
                if (!$r || !drCanSee($pdo, $p, $r)) {
                    throw new PortalWriteError("No activity report with that id that you may see.", 404);
                }
                if (drAnySentBack($r)) {
                    throw new PortalWriteError("An activity is waiting on changes. Review the report once it's done again.", 409);
                }
                if (!drCanReviewReport($pdo, $p, $r)) {
                    throw new PortalWriteError($r["status"] === "planned" ? "It isn't closed yet." : "You can't review this report.", 403);
                }
                $more = count(array_filter($r["activities"], "drAwaiting"));
                return [
                    "args" => ["id" => $id, "note" => $note],
                    "summary" => "Mark " . $r["admin_name"] . "'s activity report for " . $r["report_date"] . " as reviewed"
                        . ($more ? ", approving the " . $more . " Done activit" . ($more === 1 ? "y" : "ies") . " not reviewed yet" : "")
                        . ($note !== "" ? ", with the note " . quoteFor($note, 300) : "") . ". It locks, and they're notified.",
                    "preview" => ["report_id" => $id, "status" => "reviewed", "also_approves" => $more],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                drReviewReport($pdo, $p, $a["id"], $a["note"]);
                return ["report_id" => $a["id"], "status" => "reviewed"];
            },
        ],

        // ── Projects ─────────────────────────────────────────────────────

        "post_project_update" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $projectId = argInt($a, "project");
                $title = argText($a, "title", 200);
                $body = argText($a, "body", TASK_DESCRIPTION_MAX_CHARS, false);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                $link = normalisePortalLink($a["link"] ?? "");
                $publish = argBool($a, "publish", true);
                $project = loadScopedProject($pdo, $p, $projectId);
                if (!$project) {
                    throw new PortalWriteError("No project with that id among the projects you can see.", 404);
                }
                if (!canWriteTask($pdo, $p, $project)) {
                    throw new PortalWriteError("You are not on this project's team.", 403);
                }
                $client = clientLabelFor(["name" => $project["client_name"] ?? "the client", "company_name" => $project["company_name"]]);
                $later = $date !== null && $date > date("Y-m-d");
                $args = ["project" => $projectId, "title" => $title, "body" => $body, "date" => $date, "time" => $time,
                         "link" => $link, "publish" => $publish];
                $extras = [];
                phaseArgs($pdo, $a, $args, $extras, $project);
                // What the phase carries for the team, after what the client sees.
                $forTeam = $extras ? " For the team: " . implode("; ", array_map(function ($c) {
                    return $c["field"] . " " . quoteFor($c["to"], 80);
                }, $extras)) . (array_key_exists("team", $args) || array_key_exists("assignments", $args)
                    ? " (each person added is notified)." : ".") : "";
                return [
                    "args" => $args,
                    "summary" => ($publish
                        ? "Publish the update " . quoteFor($title) . " on the project " . quoteFor($project["title"]) . ". "
                          . ($later ? "$client can see it from $date, and is notified once it is live there."
                                    : "$client can see it straight away, and is notified.")
                        : "Save the update " . quoteFor($title) . " on " . quoteFor($project["title"])
                          . " as a draft. Only the project team can see it.") . $forTeam,
                    "preview" => [
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "client" => $client,
                        "title" => $title, "date" => $date, "time" => $time, "link" => $link,
                        "status" => $publish ? "published" : "draft",
                        "priority" => $args["priority"] ?? "medium",
                        "team" => $args["team"] ?? [],
                        "assignments" => array_column($args["assignments"] ?? [], "admin_id"),
                        "gallery" => $args["images"] ?? [],
                        "untrusted_content" => [
                            "body" => $body,
                            "notes" => $args["notes"] ?? null,
                            "work_notes" => array_column($args["assignments"] ?? [], "work_note"),
                        ],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                ensureNotificationsTable($pdo);
                $project = loadScopedProject($pdo, $p, $a["project"]);
                // The phase and its people in one transaction (audit D-04).
                [$id, $newcomers] = inPhaseTransaction($pdo, function () use ($pdo, $p, $project, $a) {
                    $id = saveProjectTask($pdo, $p, $project, null, [
                        "title" => $a["title"],
                        "link" => $a["link"],
                        "description" => $a["body"] !== "" ? textToHtml($a["body"]) : "",
                        "scheduled_date" => $a["date"],
                        "scheduled_time" => $a["time"] !== null ? $a["time"] . ":00" : null,
                        "status" => $a["publish"] ? "published" : "draft",
                    ] + phaseColumnsFrom($a));
                    return [$id, savePhasePeople($pdo, $p, $project, $id, $a["title"], $a)];
                });
                notifyAddedToPhase($pdo, $p, $project, $id, $a["title"], $newcomers);
                $gallery = !empty($a["images"]) ? savePhaseGallery($pdo, ["id" => $id], $a["images"]) : 0;
                return [
                    "task_id" => $id,
                    "status" => $a["publish"] ? "published" : "draft",
                    "gallery" => $gallery,
                    "client_notified" => announceTaskIfLive($pdo, $p, $project, $id, $a["title"]),
                ];
            },
        ],

        "update_task" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                [$task, $project] = loadEditableTask($pdo, $p, $id);
                $args = ["id" => $id];
                $changes = [];
                $change = function ($field, $from, $to) use (&$changes) {
                    noteChange($changes, $field, $from, $to);
                };
                if (array_key_exists("title", $a)) {
                    $args["title"] = argText($a, "title", 200);
                    $change("title", $task["title"], $args["title"]);
                }
                if (array_key_exists("body", $a)) {
                    $args["body"] = argText($a, "body", TASK_DESCRIPTION_MAX_CHARS, false);
                    $change("body", v1PlainText($task["description"] ?? ""), $args["body"]);
                }
                if (array_key_exists("date", $a)) {
                    $args["date"] = argDate($a, "date");
                    $change("date", $task["scheduled_date"], $args["date"]);
                }
                if (array_key_exists("time", $a)) {
                    $args["time"] = argTime($a, "time");
                    $change("time", $task["scheduled_time"] === null ? null : substr($task["scheduled_time"], 0, 5), $args["time"]);
                }
                if (array_key_exists("link", $a)) {
                    $args["link"] = normalisePortalLink($a["link"]);
                    $change("link", $task["link"], $args["link"]);
                }
                if (array_key_exists("status", $a)) {
                    $args["status"] = argEnum($a, "status", ["draft", "published"]);
                    $change("status", $task["status"], $args["status"]);
                }
                if (array_key_exists("is_complete", $a)) {
                    throw new PortalWriteError("is_complete is not set by hand: a phase completes once everyone assigned to it is done.");
                }
                phaseArgs($pdo, $a, $args, $changes, $project, $task);
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - every value given is what the task already has.");
                }
                $client = clientLabelFor(["name" => $project["client_name"] ?? "the client", "company_name" => $project["company_name"]]);
                $nowDraft = ($args["status"] ?? $task["status"]) === "draft";
                return [
                    "args" => $args,
                    "summary" => "Change the update " . quoteFor($task["title"]) . " on " . quoteFor($project["title"]) . ": "
                        . describeChanges($changes) . ". " . ($nowDraft ? "It stays a draft only the team sees." : "$client can see it once its date has come.")
                        . (array_key_exists("team", $args) || array_key_exists("assignments", $args) ? " Each person added is notified." : ""),
                    "preview" => [
                        "task_id" => $id,
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "untrusted_content" => ["changes" => $changes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                ensureNotificationsTable($pdo);
                [$task, $project] = loadEditableTask($pdo, $p, $a["id"]);
                $title = $a["title"] ?? $task["title"];
                // The phase and its people in one transaction (audit D-04).
                $newcomers = inPhaseTransaction($pdo, function () use ($pdo, $p, $project, $task, $title, $a) {
                    saveProjectTask($pdo, $p, $project, $task, [
                        "title" => $title,
                        "link" => array_key_exists("link", $a) ? $a["link"] : $task["link"],
                        "description" => array_key_exists("body", $a)
                            ? ($a["body"] !== "" ? textToHtml($a["body"]) : "")
                            : (string) ($task["description"] ?? ""),
                        "orientation" => $task["orientation"],
                        "image_path" => $task["image_path"],
                        "scheduled_date" => array_key_exists("date", $a) ? $a["date"] : $task["scheduled_date"],
                        "scheduled_time" => array_key_exists("time", $a)
                            ? ($a["time"] !== null ? $a["time"] . ":00" : null)
                            : $task["scheduled_time"],
                        "status" => $a["status"] ?? $task["status"],
                    ] + phaseColumnsFrom($a));
                    return savePhasePeople($pdo, $p, $project, $a["id"], $title, $a);
                });
                notifyAddedToPhase($pdo, $p, $project, $a["id"], $title, $newcomers);
                $result = [
                    "task_id" => (int) $a["id"],
                    "client_notified" => announceTaskIfLive($pdo, $p, $project, $a["id"], $title),
                ];
                if (array_key_exists("images", $a)) {
                    $result["gallery"] = savePhaseGallery($pdo, $task, $a["images"]);
                }
                return $result;
            },
        ],

        "set_my_phase_status" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $task = $stmt->fetch();
                $project = $task ? loadScopedProject($pdo, $p, $task["project_id"]) : null;
                if (!$task || !$project) {
                    throw new PortalWriteError("No phase with that id on the projects you can see.", 404);
                }
                $args = ["id" => $id];
                $changes = [];
                $people = taskTeamPayload($pdo, $id);
                // done, the old teammate box, is read as status done / todo.
                if (array_key_exists("done", $a)) {
                    if (array_key_exists("status", $a)) {
                        throw new PortalWriteError("Give status only - done is the old name for status done.");
                    }
                    $a["status"] = argBool($a, "done", false) ? "done" : "todo";
                }
                if (array_key_exists("status", $a)) {
                    $args["status"] = argEnum($a, "status", array_keys(TASK_ASSIGNMENT_STATUSES));
                    $mine = array_values(array_filter($people["assignments"], function ($r) use ($p) {
                        return $r["admin_id"] === (int) $p["id"];
                    }));
                    if (!$mine) {
                        throw new PortalWriteError("Only someone assigned to this phase can change their status.", 403);
                    }
                    noteChange($changes, "your status", strtolower(TASK_ASSIGNMENT_STATUSES[$mine[0]["status"]] ?? $mine[0]["status"]),
                        strtolower(TASK_ASSIGNMENT_STATUSES[$args["status"]]));
                }
                if (!array_key_exists("status", $a)) {
                    throw new PortalWriteError("Give status: todo, in_progress or done.");
                }
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - that is already where you stand on this phase.");
                }
                $total = count($people["assignments"]);
                $doneAfter = count(array_filter($people["assignments"], function ($r) use ($p, $args) {
                    return ($r["admin_id"] === (int) $p["id"] ? $args["status"] : $r["status"]) === "done";
                }));
                return [
                    "args" => $args,
                    "summary" => "On the phase " . quoteFor($task["title"]) . " of " . quoteFor($project["title"]) . ": "
                        . describeChanges($changes) . ". Only your own part changes; the team sees it."
                        . ($total && $doneAfter === $total ? " Everyone assigned is then done, so the phase is complete." : ""),
                    "preview" => [
                        "phase_id" => $id,
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "status" => $args["status"],
                        "done_after" => "$doneAfter of $total",
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                $status = setOwnPhaseStatus($pdo, $a["id"], $p["id"], $a["status"]);
                $stmt = $pdo->prepare("SELECT is_complete FROM project_tasks WHERE id = ?");
                $stmt->execute([(int) $a["id"]]);
                return ["phase_id" => (int) $a["id"], "status" => $status, "is_complete" => (int) $stmt->fetchColumn() === 1];
            },
        ],

        "delete_task" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                // The same rule as the page's Delete: the Director, an account
                // manager or the phase's creator.
                [$task, $project] = loadEditableTask($pdo, $p, $id);
                $people = taskTeamPayload($pdo, $id);
                $files = attachmentsFor($pdo, "task", [$id])[$id] ?? [];
                return [
                    "args" => ["id" => $id],
                    "summary" => "Delete the phase " . quoteFor($task["title"]) . " from " . quoteFor($project["title"])
                        . ", with its " . count($people["teammates"]) . " teammate(s), " . count($people["assignments"])
                        . " assignment(s), " . imageCount(count(taskGallery($task)) + ($task["image_path"] ? 1 : 0))
                        . " and " . count($files) . " attached file(s). The client stops seeing it. This cannot be undone.",
                    "preview" => [
                        "task_id" => $id,
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "title" => (string) $task["title"],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                ensureAttachmentsTable($pdo);
                [$task] = loadEditableTask($pdo, $p, $a["id"]);
                deleteProjectTask($pdo, $task);
                return ["task_id" => (int) $a["id"], "deleted" => true];
            },
        ],

        // ── Settings (the owner's; admin-settings-writes.php) ────────────

        "update_website_address" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $address = checkWebsiteAddress(argText($a, "address", 500, false));
                $now = getSiteSetting($pdo, "website_url", "");
                return [
                    "args" => ["address" => $address],
                    "summary" => $address === ""
                        ? "Clear the website address, so the logo on public pages is no longer a link."
                        : "Set the website address to " . normaliseWebUrl($address)
                          . ($now !== "" ? " (now " . normaliseWebUrl($now) . ")" : "")
                          . ". The logo on every public page links there.",
                    "preview" => ["address_now" => $now === "" ? null : $now, "address_after" => $address === "" ? null : $address],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                saveWebsiteAddress($pdo, $a["address"]);
                return ["website_address" => $a["address"] === "" ? null : $a["address"]];
            },
        ],

        "set_logo" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $image = argImageLink($a, "image");
                return [
                    "args" => ["image" => $image],
                    "summary" => "Replace the portal's logo, on every page, with the image at " . $image
                        . ". It is downloaded and checked (PNG or JPG, 3 MB at most) when you confirm.",
                    "preview" => ["image" => $image],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                fetchSiteLogo($a["image"]);
                return ["logo_url" => siteLogoUrl($pdo, APP_URL)];
            },
        ],

        "add_login_slide" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $image = argImageLink($a, "image");
                $title = loginSlideTitle(argText($a, "title", 120, false));
                $subtitle = loginSlideSubtitle(argText($a, "subtitle", 255, false));
                checkLoginSlideRoom($pdo);
                return [
                    "args" => ["image" => $image, "title" => $title, "subtitle" => $subtitle],
                    "summary" => "Add a slide to the sign-in page, after the others, with the image at " . $image
                        . ($title !== "" ? ", titled " . quoteFor($title) : "")
                        . ". The image is downloaded and checked (at least " . LOGIN_SLIDE_MIN_WIDTH . " × "
                        . LOGIN_SLIDE_MIN_HEIGHT . " px) when you confirm.",
                    "preview" => ["image" => $image, "untrusted_content" => ["title" => $title, "subtitle" => $subtitle]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $imagePath = fetchLoginSlideImage($a["image"]);
                $slide = addLoginSlide($pdo, $imagePath, $a["title"], $a["subtitle"], $p["id"]);
                return ["slide_id" => $slide["id"], "position" => $slide["position"]];
            },
        ],

        "update_login_slide" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $slideId = argInt($a, "slide");
                $slide = findLoginSlide($pdo, $slideId);
                $changes = [];
                $args = ["slide" => $slideId, "title" => (string) $slide["title"], "subtitle" => (string) $slide["subtitle"]];
                foreach (["title" => 120, "subtitle" => 255] as $field => $max) {
                    if (array_key_exists($field, $a) && $a[$field] !== null) {
                        $args[$field] = mb_substr(argText($a, $field, $max, false), 0, $max);
                        noteChange($changes, $field, (string) $slide[$field], $args[$field]);
                    }
                }
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - give a new title or subtitle.");
                }
                return [
                    "args" => $args,
                    "summary" => "Change sign-in slide " . ((int) $slide["position"] + 1) . ": " . describeChanges($changes) . ".",
                    "preview" => ["slide_id" => $slideId, "untrusted_content" => ["changes" => $changes]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                updateLoginSlideText($pdo, $a["slide"], $a["title"], $a["subtitle"]);
                return ["slide_id" => $a["slide"], "saved" => true];
            },
        ],

        "reorder_login_slides" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $order = argList($a, "order", 1, LOGIN_SLIDES_MAX);
                try {
                    $ids = checkLoginSlideOrder($pdo, $order);
                } catch (PortalWriteError $e) {
                    throw new PortalWriteError("order must list every slide's id once - get_settings has them.");
                }
                return [
                    "args" => ["order" => $ids],
                    "summary" => "Show the sign-in page slides in this order: " . implode(", ", array_map(function ($id) {
                        return "slide " . $id;
                    }, $ids)) . ".",
                    "preview" => ["order" => $ids],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                reorderLoginSlides($pdo, $a["order"]);
                return ["order" => $a["order"]];
            },
        ],

        "remove_login_slide" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $slideId = argInt($a, "slide");
                $slide = findLoginSlide($pdo, $slideId);
                return [
                    "args" => ["slide" => $slideId],
                    "summary" => "Remove sign-in slide " . ((int) $slide["position"] + 1)
                        . ($slide["title"] ? " (" . quoteFor($slide["title"]) . ")" : "")
                        . " and delete its image. This can't be undone.",
                    "preview" => [
                        "slide_id" => $slideId,
                        "image_url" => APP_URL . "/" . ltrim($slide["image_path"], "/"),
                        "untrusted_content" => ["title" => $slide["title"], "subtitle" => $slide["subtitle"]],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                removeLoginSlide($pdo, $a["slide"]);
                return ["slide_id" => $a["slide"], "removed" => true];
            },
        ],

        // ── The admin team (the owner's; admin-settings-writes.php) ──────

        "add_admin" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $role = argEnum($a, "role", ASSIGNABLE_ADMIN_ROLES);
                [$name, $email] = checkNewAdmin($pdo, argText($a, "name", 100), argText($a, "email", 190), $role);
                $position = checkAdminPosition($a["position"] ?? "");
                return [
                    "args" => ["name" => $name, "email" => $email, "role" => $role, "position" => $position],
                    "summary" => "Add " . quoteFor($name) . " (" . $email . ") to the admin team as " . adminRoleName($role)
                        . ", with the position " . quoteFor($position) . " - shown in their sidebar instead of their name"
                        . ". They are emailed a link to choose their password, good for 3 days.",
                    "preview" => ["role" => $role, "untrusted_content" => ["name" => $name, "email" => $email, "position" => $position]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $id = createAdmin($pdo, $a["name"], $a["email"], $a["role"], null, $a["position"]);
                $emailed = emailNewAdminWelcome($pdo, $id, $a["name"], $a["email"], $a["role"], $p["name"]);
                return [
                    "admin_id" => $id,
                    "emailed" => $emailed,
                    "note" => $emailed ? null
                        : "The email didn't go out. They can use \"Forgot password\" on the admin sign-in page with this address.",
                ];
            },
        ],

        "update_admin" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $target = findAdminRow($pdo, $adminId);
                $given = [];
                foreach (["name" => 100, "email" => 190, "position" => 100] as $key => $max) {
                    if (array_key_exists($key, $a)) {
                        $given[$key] = argText($a, $key, $max, false);
                    }
                }
                if (array_key_exists("role", $a)) {
                    $given["role"] = argEnum($a, "role", ASSIGNABLE_ADMIN_ROLES);
                }
                if (!$given) {
                    throw new PortalWriteError("Give at least one of name, email, position or role.");
                }
                // The Settings popup's rules, on the row as it would be after.
                [$target, $clean] = checkAdminUpdate($pdo, $p, $adminId, $given + [
                    "name" => $target["name"],
                    "email" => $target["email"],
                    "position" => $target["position"] ?? "",
                    "role" => $target["role"],
                ]);
                $changes = [];
                noteChange($changes, "name", $target["name"], $clean["name"]);
                noteChange($changes, "email", $target["email"], $clean["email"]);
                noteChange($changes, "position", $target["position"] ?? "", $clean["position"]);
                noteChange($changes, "role", adminRoleName($target["role"]), adminRoleName($clean["role"]));
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - " . adminLabel($target) . " already has those details.", 409);
                }
                $roleChanges = $clean["role"] !== $target["role"];
                return [
                    "args" => ["admin" => $adminId] + $clean,
                    "summary" => "Change " . adminLabel($target) . ": " . describeChanges($changes) . "."
                        . ($roleChanges ? " They are signed out and pick up the new role when they next sign in." : " Nobody is signed out."),
                    "preview" => [
                        "admin_id" => $adminId,
                        "signs_out" => $roleChanges,
                        "untrusted_content" => ["changes" => $changes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                [$target, $clean] = checkAdminUpdate($pdo, $p, $a["admin"], $a);
                return ["admin_id" => $a["admin"], "signed_out" => updateAdmin($pdo, $target, $clean)];
            },
        ],

        "set_admin_photo" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $target = checkAdminTarget($pdo, $p, findAdminRow($pdo, $adminId), "edit");
                // An https image link, checked now and downloaded on confirm; "" removes the photo.
                $url = trim((string) ($a["image"] ?? ""));
                if ($url !== "") {
                    $url = argImageLink($a, "image");
                }
                return [
                    "args" => ["admin" => $adminId, "image" => $url],
                    "summary" => ($url !== "" ? "Set the photo of " . adminLabel($target) . " to the image at the link given"
                                              : "Remove the photo of " . adminLabel($target))
                        . ". It shows in the admin list and their sidebar. Nobody is signed out.",
                    "preview" => ["admin_id" => $adminId, "untrusted_content" => ["name" => $target["name"], "image" => $url !== "" ? $url : null]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $avatar = "";
                if ($a["image"] !== "") {
                    // Downloaded through the same checked fetch as post images,
                    // then moved under uploads/avatars/.
                    [$tmp, $ext] = downloadContentImage(0, $a["image"]);
                    if ($ext === "gif") {
                        @unlink($tmp);
                        throw new PortalWriteError("Only JPG, PNG and WEBP photos are allowed.");
                    }
                    $dir = dirname(__DIR__, 2) . "/uploads/avatars/";
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $name = bin2hex(random_bytes(16)) . "." . $ext;
                    if (!rename($tmp, $dir . $name)) {
                        @unlink($tmp);
                        throw new PortalWriteError("Could not save the photo.", 500);
                    }
                    $avatar = "uploads/avatars/" . $name;
                }
                $old = setAdminAvatar($pdo, $a["admin"], $avatar);
                if ($old && $old !== $avatar) {
                    deleteAdminAvatar($old);
                }
                return ["admin_id" => $a["admin"], "avatar_url" => $avatar !== "" ? APP_URL . "/" . $avatar : null];
            },
        ],

        "set_role_permissions" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $role = argEnum($a, "role", PERMISSION_ROLES);
                $changes = v1PermissionChanges($pdo, $p, $a, function ($changes) use ($p, $role) {
                    return checkRolePermissionChange($p, $role, $changes);
                });
                return [
                    "args" => ["role" => $role, "changes" => $changes],
                    "summary" => "For every " . adminRoleName($role) . ": " . permissionChangeSummary($changes, "the default")
                        . ". It applies on their next request; nobody is signed out.",
                    "preview" => ["role" => $role, "changes" => $changes],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $changes = v1PermissionChanges($pdo, $p, $a, function ($changes) use ($p, $a) {
                    return checkRolePermissionChange($p, $a["role"], $changes);
                });
                $pdo->beginTransaction();
                setRolePermissions($pdo, $p, $a["role"], $changes);
                $pdo->commit();
                return ["role" => $a["role"], "allowed" => array_keys(array_filter(rolePermissionMatrix($pdo)[$a["role"]]))];
            },
        ],

        "set_admin_permissions" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $target = null;
                $changes = v1PermissionChanges($pdo, $p, $a, function ($changes) use ($pdo, $p, $adminId, &$target) {
                    [$target, $clean] = checkAdminPermissionChange($pdo, $p, $adminId, $changes);
                    return $clean;
                });
                return [
                    "args" => ["admin" => $adminId, "changes" => $changes],
                    "summary" => "For " . adminLabel($target) . ": " . permissionChangeSummary($changes, "their role")
                        . ". It applies on their next request; nobody is signed out.",
                    "preview" => [
                        "admin_id" => $adminId,
                        "role" => $target["role"],
                        "changes" => $changes,
                        "untrusted_content" => ["name" => $target["name"]],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $target = null;
                $changes = v1PermissionChanges($pdo, $p, $a, function ($changes) use ($pdo, $p, $a, &$target) {
                    [$target, $clean] = checkAdminPermissionChange($pdo, $p, $a["admin"], $changes);
                    return $clean;
                });
                $pdo->beginTransaction();
                setAdminPermissions($pdo, $p, $target, $changes);
                $pdo->commit();
                forgetPermissions();
                return ["admin_id" => (int) $a["admin"], "effective" => effectivePermissions($target, $pdo)];
            },
        ],

        "update_my_profile" => [
            "scope" => "settings:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                // The Director's own card - a system rule, not a permission.
                if (!isDirector($p)) {
                    throw new PortalWriteError("Only the Director has this card.", 403);
                }
                $now = adminProfile($pdo, $p["id"]);
                [$name, $position] = checkOwnProfile(
                    array_key_exists("name", $a) ? argText($a, "name", 100) : $now["name"],
                    array_key_exists("position", $a) ? argText($a, "position", 100, false) : $now["position"]
                );
                $changes = [];
                noteChange($changes, "name", $now["name"], $name);
                noteChange($changes, "position", $now["position"], $position);
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - your name and position are already those.", 409);
                }
                return [
                    "args" => ["name" => $name, "position" => $position],
                    "summary" => "Change your Director card: " . describeChanges($changes)
                        . ". Your email and role stay as they are.",
                    "preview" => ["untrusted_content" => ["changes" => $changes]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                updateOwnProfile($pdo, $p["id"], $a["name"], $a["position"]);
                return ["director" => adminProfile($pdo, $p["id"])];
            },
        ],

        "set_admin_position" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $position = checkAdminPosition($a["position"] ?? "");
                $target = findAdminRow($pdo, $adminId);
                // Their own position is a label anyone may set; someone else's
                // follows the target rules.
                if ((int) $target["id"] !== (int) $p["id"]) {
                    checkAdminTarget($pdo, $p, $target, "edit");
                }
                if (trim((string) ($target["position"] ?? "")) === $position) {
                    throw new PortalWriteError(adminLabel($target) . " already has that position.", 409);
                }
                $now = trim((string) ($target["position"] ?? ""));
                return [
                    "args" => ["admin" => $adminId, "position" => $position],
                    "summary" => "Set " . adminLabel($target) . "'s position to " . quoteFor($position)
                        . ($now !== "" ? " (it is " . quoteFor($now) . ")" : " (they have none, so their name shows)")
                        . ". It replaces their name in their sidebar and account popup. Nobody is signed out.",
                    "preview" => [
                        "admin_id" => $adminId,
                        "untrusted_content" => ["name" => $target["name"], "email" => $target["email"],
                                                "position_now" => $now !== "" ? $now : null, "position_after" => $position],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                setAdminPosition($pdo, $a["admin"], $a["position"]);
                return ["admin_id" => $a["admin"], "position" => $a["position"]];
            },
        ],

        "change_admin_role" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $role = argEnum($a, "role", ASSIGNABLE_ADMIN_ROLES);
                $target = checkAdminRoleChange($pdo, $p, $adminId, $role);
                if ($target["role"] === $role) {
                    throw new PortalWriteError(adminLabel($target) . " is already " . adminRoleName($role) . ".", 409);
                }
                return [
                    "args" => ["admin" => $adminId, "role" => $role],
                    "summary" => "Change " . adminLabel($target) . " from " . adminRoleName($target["role"]) . " to "
                        . adminRoleName($role) . ". They are signed out and get the new role when they next sign in.",
                    "preview" => [
                        "admin_id" => $adminId,
                        "role_now" => $target["role"],
                        "role_after" => $role,
                        "untrusted_content" => ["name" => $target["name"], "email" => $target["email"]],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                changeAdminRole($pdo, $a["admin"], $a["role"]);
                return ["admin_id" => $a["admin"], "role" => $a["role"], "signed_out" => true];
            },
        ],

        "set_admin_active" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $adminId = argInt($a, "admin");
                $active = argBool($a, "active", null);
                if ($active === null) {
                    throw new PortalWriteError("active is required.");
                }
                $target = checkAdminActiveChange($pdo, $p, $adminId);
                if (((int) $target["active"] === 1) === $active) {
                    throw new PortalWriteError(adminLabel($target) . " is already " . ($active ? "active" : "deactivated") . ".", 409);
                }
                return [
                    "args" => ["admin" => $adminId, "active" => $active],
                    "summary" => $active
                        ? "Activate " . adminLabel($target) . " so they can sign in again, with everything as it was."
                        : "Deactivate " . adminLabel($target) . ": they can no longer sign in. Nothing of theirs is removed.",
                    "preview" => [
                        "admin_id" => $adminId,
                        "active_now" => (int) $target["active"] === 1,
                        "active_after" => $active,
                        "untrusted_content" => ["name" => $target["name"], "email" => $target["email"]],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                setAdminActive($pdo, $a["admin"], $a["active"]);
                return ["admin_id" => $a["admin"], "active" => $a["active"]];
            },
        ],

        "assign_admins_to_manager" => [
            "scope" => "admins:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $managerId = argInt($a, "manager");
                $given = argList($a, "admins", 0, 200);
                foreach ($given as $id) {
                    if (!is_int($id) || $id <= 0) {
                        throw new PortalWriteError("admins must be a list of admin ids.");
                    }
                }
                [$manager, $adminIds] = checkManagerAssignment($pdo, $p, $managerId, $given);
                $dropped = array_values(array_diff(array_unique($given), $adminIds));
                if ($dropped) {
                    throw new PortalWriteError(adminRoleName($manager["role"]) . "s can't manage admin "
                        . implode(", ", $dropped) . ". A super admin manages Admins (seo_admin); an account manager, "
                        . "Admins and super admins.");
                }
                $current = $pdo->prepare("SELECT id FROM admins WHERE managed_by_admin_id = ? ORDER BY id");
                $current->execute([$managerId]);
                $now = array_map("intval", $current->fetchAll(PDO::FETCH_COLUMN));
                $names = adminNames($pdo, $adminIds);
                return [
                    "args" => ["manager" => $managerId, "admins" => $adminIds],
                    "summary" => $adminIds
                        ? "Set who " . adminLabel($manager) . " manages to: " . $names
                          . ". Anyone they manage now who isn't listed is taken off; anyone listed who had another manager moves to them."
                        : "Take everyone off " . adminLabel($manager) . ", so they manage no one.",
                    "preview" => [
                        "manager_id" => $managerId,
                        "manages_now" => $now,
                        "manages_after" => $adminIds,
                        "untrusted_content" => ["manager" => $manager["name"], "admins_after" => $names],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                [, $adminIds, $allowedRoles] = checkManagerAssignment($pdo, $p, $a["manager"], $a["admins"]);
                assignAdminsToManager($pdo, $a["manager"], $adminIds, $allowedRoles);
                return ["manager_id" => $a["manager"], "admins" => $adminIds];
            },
        ],

        // ── A client's own account ───────────────────────────────────────

        // A client's team
        // The rules are client-team-writes.php's, the same as the Team section of profile.html.

        "invite_teammate" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                requireAccountOwner(clientRow($pdo, $p));
                $email = strtolower(argText($a, "email", 190));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new PortalWriteError("That does not look like an email address.");
                }
                return [
                    "args" => ["email" => $email],
                    "summary" => "Invite " . $email . " to your team. They get an email with a link to set a password, and then see everything on this account.",
                    "preview" => ["email" => $email],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $owner = clientRow($pdo, $p);
                requireTeamInviteQuota($pdo, $owner);
                $sent = inviteTeammate($pdo, $owner, $a["email"]);
                return ["invite_id" => $sent["id"], "emailed" => $sent["emailed"]];
            },
        ],

        "resend_team_invite" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $invite = openTeamInvite($pdo, $p, argInt($a, "id"));
                return [
                    "args" => ["id" => (int) $invite["id"]],
                    "summary" => "Send " . $invite["email"] . " a new invitation link. The old link stops working once the new one has gone out.",
                    "preview" => ["email" => $invite["email"]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $owner = clientRow($pdo, $p);
                requireTeamInviteQuota($pdo, $owner);
                $sent = resendClientInvite($pdo, $owner, $a["id"]);
                return ["invite_id" => $sent["id"], "emailed" => $sent["emailed"]];
            },
        ],

        "revoke_team_invite" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $invite = openTeamInvite($pdo, $p, argInt($a, "id"));
                return [
                    "args" => ["id" => (int) $invite["id"]],
                    "summary" => "Revoke the invitation sent to " . $invite["email"] . ". Its link stops working.",
                    "preview" => ["email" => $invite["email"]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                revokeClientInvite($pdo, clientRow($pdo, $p), $a["id"]);
                return ["revoked" => true];
            },
        ],

        "remove_teammate" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $owner = clientRow($pdo, $p);
                requireAccountOwner($owner);
                $id = argInt($a, "id");
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND account_owner_id = ? LIMIT 1");
                $stmt->execute([$id, $owner["account_id"]]);
                $person = $stmt->fetch();
                if (!$person) {
                    throw new PortalWriteError("That person isn't on your team.", 404);
                }
                return [
                    "args" => ["id" => (int) $person["id"]],
                    "summary" => "Remove " . $person["email"] . " from your team. They lose access straight away; what they've already submitted stays on the account.",
                    "preview" => ["email" => $person["email"], "untrusted_content" => ["name" => $person["name"]]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                removeTeammate($pdo, clientRow($pdo, $p), $a["id"]);
                return ["removed" => true];
            },
        ],

        "request_meeting" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($date === null || $time === null) {
                    throw new PortalWriteError("date and time are required.");
                }
                if ($date < date("Y-m-d")) {
                    throw new PortalWriteError("That date has passed.");
                }
                $topic = argText($a, "topic", 200);
                $notes = argText($a, "notes", 2000, false);
                return [
                    "args" => ["date" => $date, "time" => $time, "topic" => $topic, "notes" => $notes],
                    "summary" => "Ask your team for a meeting on $date at $time about " . quoteFor($topic)
                        . ". Your team is told in the portal and by email, and will accept or decline it.",
                    "preview" => ["date" => $date, "time" => $time, "untrusted_content" => ["topic" => $topic, "notes" => $notes]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                $id = requestMeetingFromAdmins($pdo, clientRow($pdo, $p), $a["date"], $a["time"], $a["topic"], $a["notes"]);
                return ["meeting_id" => $id, "status" => "pending"];
            },
        ],

        "pick_meeting_time" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                $id = argInt($a, "id");
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($date === null || $time === null) {
                    throw new PortalWriteError("date and time are required - one of the open times from list_my_booking_requests.");
                }
                $message = argText($a, "message", 1000, false);
                $stmt = $pdo->prepare("SELECT id FROM booking_request_links WHERE booking_request_id = ? AND user_id = ? LIMIT 1");
                $stmt->execute([$id, $p["account_id"]]);
                $request = $stmt->fetchColumn() ? bookingRequestById($pdo, $id) : null;
                if (!$request) {
                    throw new PortalWriteError("No booking request to you with that id.", 404);
                }
                if (bookingRequestState($pdo, $request) !== "open") {
                    throw new PortalWriteError("That booking request is closed.", 409);
                }
                if (!slotIsOpen($pdo, (int) $request["admin_id"], $date, $time, (int) $request["duration_min"])
                    || $date < $request["window_from"] || $date > $request["window_to"]) {
                    throw new PortalWriteError("That time isn't open. Pick one of the times list_my_booking_requests gives.", 409);
                }
                return [
                    "args" => ["id" => $id, "date" => $date, "time" => $time, "message" => $message],
                    "summary" => "Ask for $date at $time ({$request["timezone"]}) for " . quoteFor($request["topic"]) . " with {$request["host_name"]}."
                        . " They confirm it, and you get a calendar invite by email.",
                    "preview" => ["booking_request_id" => $id, "date" => $date, "time" => $time, "timezone" => (string) $request["timezone"],
                        "untrusted_content" => ["topic" => (string) $request["topic"], "message" => $message]],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                $me = clientRow($pdo, $p);
                $meetingId = pickBookingSlot($pdo, $a["id"], $a["date"], $a["time"], [
                    "account_id" => (int) $p["account_id"], "person_id" => (int) $p["id"],
                    "name" => (string) $me["name"], "email" => (string) $me["email"], "message" => $a["message"],
                ]);
                return ["meeting_id" => $meetingId, "status" => "pending"];
            },
        ],

        "respond_to_my_meeting" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $decision = argEnum($a, "decision", ["accept", "decline"]);
                $stmt = $pdo->prepare("
                    SELECT a.date, a.time, a.status, a.topic, a.title, ad.name AS admin_name
                    FROM appointments a
                    LEFT JOIN admins ad ON ad.id = a.admin_id
                    WHERE a.id = ? AND a.user_id = ? AND a.requested_by = 'admin'
                    LIMIT 1
                ");
                $stmt->execute([$id, $p["account_id"]]);
                $request = $stmt->fetch();
                if (!$request) {
                    throw new PortalWriteError("No meeting request to you with that id.", 404);
                }
                if ($request["status"] !== "pending") {
                    throw new PortalWriteError("You have already answered that request ({$request["status"]}).", 409);
                }
                $topic = (string) ($request["topic"] ?? $request["title"] ?? "");
                return [
                    "args" => ["id" => $id, "decision" => $decision],
                    "summary" => ($decision === "accept" ? "Accept" : "Decline") . " the meeting on {$request["date"]} at "
                        . substr((string) $request["time"], 0, 5) . " about " . quoteFor($topic)
                        . ($request["admin_name"] ? ". " . $request["admin_name"] . " is told." : "."),
                    "preview" => [
                        "meeting_id" => $id,
                        "date" => (string) $request["date"],
                        "time" => substr((string) $request["time"], 0, 5),
                        "with" => v1NullableString($request["admin_name"]),
                        "decision" => $decision,
                        "untrusted_content" => ["topic" => $topic],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                answerAdminMeetingRequest($pdo, clientRow($pdo, $p), $a["id"], $a["decision"] === "accept" ? "approved" : "rejected", true);
                return ["meeting_id" => $a["id"], "status" => $a["decision"] === "accept" ? "approved" : "rejected"];
            },
        ],

        "submit_my_form" => [
            "scope" => "self:write",
            "kind" => "user",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $form = loadClientSurvey($pdo, $p["account_id"], $id);
                if (!$form) {
                    throw new PortalWriteError("No form of yours with that id.", 404);
                }
                if ($form["status"] === "completed") {
                    throw new PortalWriteError("You have already sent this form.", 409);
                }
                $given = argList($a, "answers", 1, 100);
                $answers = clientFormAnswers($pdo, $form, $given);
                return [
                    // As given, not converted: confirm runs this prepare again
                    // on exactly these arguments before apply converts them.
                    "args" => ["id" => $id, "answers" => $given],
                    "summary" => "Send your answers to " . quoteFor($form["title"]) . " (" . count($answers)
                        . (count($answers) === 1 ? " answer" : " answers")
                        . "). Once sent they can't be changed, and your team is told.",
                    "preview" => [
                        "form" => ["id" => (int) $form["id"], "title" => (string) $form["title"]],
                        "untrusted_content" => ["answers" => array_map(function ($x) {
                            return ["question" => $x["questionLabel"], "answer" => $x["answer"]];
                        }, $answers)],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                $answers = clientFormAnswers($pdo, loadClientSurvey($pdo, $p["account_id"], $a["id"]), $a["answers"]);
                $responseId = submitSurveyResponse($pdo, clientRow($pdo, $p), $a["id"], $answers, null);
                return ["form_id" => $a["id"], "response_id" => $responseId, "status" => "completed"];
            },
        ],
    ];
}

/*
| set_role_permissions / set_admin_permissions: the Director's alone
| (permissions.manage, on top of admins:write), checked on prepare and again
| on confirm by the same rules as Settings > Permissions. "changes" is an
| object of permission key => true, false or "default". The tables are made
| here, outside any transaction - a CREATE TABLE would commit it.
*/
function v1PermissionChanges(PDO $pdo, array $p, array $a, callable $check)
{
    if (!can($p, "permissions.manage", $pdo)) {
        throw new PortalWriteError("Only the Director can change permissions.", 403);
    }
    ensurePermissionTables($pdo);
    $changes = $a["changes"] ?? null;
    if (!is_array($changes) || array_is_list($changes) && $changes !== []) {
        throw new PortalWriteError("changes must be an object of permission => true, false or \"default\".");
    }
    return $check($changes);
}
