<?php

/*
|--------------------------------------------------------------------------
| The Director's event log: writing it
|--------------------------------------------------------------------------
|
| Required by every file where an admin changes something. Reading it is
| api/admin-events.php (the page) and api/v1/events.php (the MCP); the table
| is ensureAdminEventsTable() in db.php, mirrored by sql/admin_events.sql.
|
| Who did it comes from the request itself - the browser session token or
| the MCP access token, through currentPrincipal() - so callers only say
| what happened. A request made by a client, or by nobody, logs nothing.
|
| Unlike notify(), logEvent() is happy inside a transaction: the row joins
| the caller's work, so a rolled-back change leaves no event behind and a
| committed one always has its event. The table is ensured when this file
| is required, which is at the top of the caller - before any
| beginTransaction() (CREATE TABLE commits implicitly).
|
| Nothing in this file throws. A lost event is a gap in the log; the change
| itself has already been made.
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

// $GLOBALS rather than $pdo: this file may be required from inside a function.
if (isset($GLOBALS["pdo"]) && $GLOBALS["pdo"] instanceof PDO) {
    ensureAdminEventsTable($GLOBALS["pdo"]);

    // Resolve the actor now, while the token is certainly still valid - some
    // endpoints clear the caller's own session as part of what they do.
    currentPrincipal($GLOBALS["pdo"]);
}

/* Action keys, "<area>.<verb>". The area is what the page's filter matches
   on - keep it to the EVENT_AREAS below. Constants so a typo is a PHP error
   at deploy time, not a row nobody can find. */
const EVENT_CLIENT_APPROVED       = "client.approved";
const EVENT_CLIENT_REJECTED       = "client.rejected";
const EVENT_CLIENT_DELETED        = "client.deleted";
const EVENT_CLIENT_TEAM_CHANGED   = "client.team_changed";
const EVENT_CLIENT_NOTE_SAVED     = "client.note_saved";
const EVENT_CLIENT_UPDATED        = "client.updated";

const EVENT_PROJECT_CREATED       = "project.created";
const EVENT_PROJECT_UPDATED       = "project.updated";
const EVENT_PROJECT_DELETED       = "project.deleted";

const EVENT_PHASE_CREATED         = "phase.created";
const EVENT_PHASE_UPDATED         = "phase.updated";
const EVENT_PHASE_DELETED         = "phase.deleted";
const EVENT_PHASE_STATUS          = "phase.status_changed";
const EVENT_PHASE_TEAM            = "phase.team_changed";

const EVENT_CONTENT_CREATED       = "content.created";
const EVENT_CONTENT_UPDATED       = "content.updated";
const EVENT_CONTENT_DELETED       = "content.deleted";
const EVENT_CONTENT_STATUS        = "content.status_changed";
const EVENT_CONTENT_ASSIGNEES     = "content.assignees_changed";
const EVENT_CONTENT_TYPE_CHANGED  = "content.type_changed";

const EVENT_FORM_CREATED          = "form.created";
const EVENT_FORM_UPDATED          = "form.updated";
const EVENT_FORM_REVIEWED         = "form.reviewed";

const EVENT_INQUIRY_CREATED       = "inquiry.created";
const EVENT_INQUIRY_UPDATED       = "inquiry.updated";
const EVENT_INQUIRY_DELETED       = "inquiry.deleted";
const EVENT_INQUIRY_ACCESS        = "inquiry.access_changed";

const EVENT_MEETING_PROPOSED      = "meeting.proposed";
const EVENT_MEETING_ANSWERED      = "meeting.answered";

const EVENT_SOURCE_CHANGED        = "source.changed";

const EVENT_ANNOUNCEMENT_SENT     = "announcement.sent";

const EVENT_ADMIN_CREATED         = "admin.created";
const EVENT_ADMIN_UPDATED         = "admin.updated";
const EVENT_ADMIN_REMOVED         = "admin.removed";
const EVENT_ADMIN_ROLE            = "admin.role_changed";
const EVENT_ADMIN_ACTIVE          = "admin.active_changed";
const EVENT_ADMIN_MANAGER         = "admin.manager_changed";
const EVENT_ADMIN_POSITION        = "admin.position_changed";

const EVENT_SETTINGS_CHANGED      = "settings.changed";

// The page's Area filter, in the order it shows them.
const EVENT_AREAS = [
    "client"       => "Clients",
    "project"      => "Projects",
    "phase"        => "Phases",
    "content"      => "Content",
    "form"         => "Forms",
    "inquiry"      => "Inquiries",
    "meeting"      => "Meetings",
    "source"       => "Source files",
    "announcement" => "Announcements",
    "admin"        => "Team",
    "settings"     => "Settings",
];

/**
 * One row for one change, made by whoever this request belongs to.
 *
 * $targetType names what $targetId points at ("project", "admin", "user"...);
 * $targetLabel is how a person would recognise it today. $clientId is the
 * client (users.id) the change concerns, when there is one - the page
 * filters on it.
 */
function logEvent(
    PDO $pdo,
    string $action,
    string $targetType,
    $targetId,
    $targetLabel,
    string $summary,
    $clientId = null
) {
    try {
        $p = currentPrincipal($pdo);
        if (!$p || $p["kind"] !== "admin") {
            return;
        }

        $requestId = $_SERVER["HTTP_X_REQUEST_ID"] ?? "";
        $ip = $_SERVER["REMOTE_ADDR"] ?? "";

        $pdo->prepare("
            INSERT INTO admin_events
                (actor_admin_id, actor_name, actor_role, via, action,
                 target_type, target_id, target_label, client_id,
                 summary, ip, request_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $p["id"],
            mb_substr((string) ($p["name"] ?? ""), 0, 150),
            (string) $p["role"],
            $p["via"] === "mcp" ? "mcp" : "web",
            $action,
            $targetType,
            (int) $targetId > 0 ? (int) $targetId : null,
            (string) $targetLabel !== "" ? mb_substr((string) $targetLabel, 0, 200) : null,
            (int) $clientId > 0 ? (int) $clientId : null,
            mb_substr($summary, 0, 500),
            $ip !== "" ? mb_substr($ip, 0, 45) : null,
            preg_match('/^[A-Za-z0-9-]{1,64}$/', $requestId) ? $requestId : null,
        ]);
    } catch (Throwable $e) {
        // Read-only DB user, or the table was never created. The change
        // itself already happened, so say nothing.
    }
}

/** "Sara" / "client #12": how a summary names a client. */
function eventClientName(PDO $pdo, $userId)
{
    try {
        $stmt = $pdo->prepare("SELECT name, company_name FROM users WHERE id = ?");
        $stmt->execute([(int) $userId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = null;
    }
    if (!$row) {
        return "client #" . (int) $userId;
    }
    return trim((string) ($row["company_name"] ?: $row["name"])) ?: "client #" . (int) $userId;
}

/** An admin's name for a summary. */
function eventAdminName(PDO $pdo, $adminId)
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM admins WHERE id = ?");
        $stmt->execute([(int) $adminId]);
        $name = $stmt->fetchColumn();
    } catch (Throwable $e) {
        $name = false;
    }
    return $name ? (string) $name : "admin #" . (int) $adminId;
}

/**
 * [phase title, project title, client id] for a phase's summary, with
 * blanks when it can't be found.
 */
function eventPhase(PDO $pdo, $taskId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT t.title, p.title AS project_title, p.client_id
            FROM project_tasks t
            JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $stmt->execute([(int) $taskId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = null;
    }
    return $row
        ? [(string) $row["title"], (string) $row["project_title"], (int) $row["client_id"]]
        : ["phase #" . (int) $taskId, "", null];
}

/** "Sara, Omar" for a summary; "" for nobody. */
function eventAdminNames(PDO $pdo, array $adminIds)
{
    return implode(", ", array_map(function ($id) use ($pdo) {
        return eventAdminName($pdo, $id);
    }, $adminIds));
}

/**
 * [post title, client id] for a content post's summary. A post names its
 * client by company (content.client), so the id is looked up from that.
 */
function eventContent(PDO $pdo, $contentId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT c.title, (SELECT u.id FROM users u WHERE u.company_name = c.client LIMIT 1) AS client_id
            FROM content c
            WHERE c.id = ?
        ");
        $stmt->execute([(int) $contentId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = null;
    }
    return $row ? [(string) $row["title"], (int) $row["client_id"] ?: null] : ["post #" . (int) $contentId, null];
}

/*
|--------------------------------------------------------------------------
| Reading it - shared by api/admin-events.php and api/v1/events.php
|--------------------------------------------------------------------------
*/

// How long a row is kept. There is no cron: pruneAdminEvents() runs when the
// log is read, which only the Director does.
const EVENT_RETENTION_MONTHS = 12;

function pruneAdminEvents(PDO $pdo)
{
    try {
        $pdo->exec("
            DELETE FROM admin_events
            WHERE created_at < NOW() - INTERVAL " . EVENT_RETENTION_MONTHS . " MONTH
            LIMIT 1000
        ");
    } catch (Throwable $e) {
        // Read-only DB user: rows simply stay longer.
    }
}

/**
 * [WHERE parts, params] for the log's filters, each optional and already
 * checked by the caller: actor_id (int), area (an EVENT_AREAS key), client_id
 * (int), from / to (Y-m-d, inclusive), q (text matched against the summary,
 * the target and the admin's name).
 */
function adminEventFilters(array $f)
{
    $where = [];
    $params = [];

    if (!empty($f["actor_id"])) {
        $where[] = "actor_admin_id = ?";
        $params[] = (int) $f["actor_id"];
    }
    if (!empty($f["area"]) && isset(EVENT_AREAS[$f["area"]])) {
        $where[] = "action LIKE ?";
        $params[] = $f["area"] . ".%";
    }
    if (!empty($f["client_id"])) {
        $where[] = "client_id = ?";
        $params[] = (int) $f["client_id"];
    }
    if (!empty($f["from"])) {
        $where[] = "created_at >= ?";
        $params[] = $f["from"] . " 00:00:00";
    }
    if (!empty($f["to"])) {
        $where[] = "created_at < ? + INTERVAL 1 DAY";
        $params[] = $f["to"] . " 00:00:00";
    }
    if (isset($f["q"]) && $f["q"] !== "") {
        $like = "%" . addcslashes($f["q"], "%_\\") . "%";
        $where[] = "(summary LIKE ? OR target_label LIKE ? OR actor_name LIKE ?)";
        array_push($params, $like, $like, $like);
    }

    return [$where, $params];
}

/** One row as both readers return it, before each adds its own wrapping. */
function adminEventRow(array $r)
{
    $area = strstr($r["action"], ".", true) ?: $r["action"];
    return [
        "id"           => (int) $r["id"],
        "action"       => (string) $r["action"],
        "area"         => $area,
        "area_label"   => EVENT_AREAS[$area] ?? ucfirst($area),
        "actor_id"     => (int) $r["actor_admin_id"],
        "actor_name"   => (string) $r["actor_name"],
        "actor_role"   => (string) $r["actor_role"],
        "via"          => (string) $r["via"],
        "target_type"  => (string) $r["target_type"],
        "target_id"    => $r["target_id"] !== null ? (int) $r["target_id"] : null,
        "target_label" => $r["target_label"] !== null ? (string) $r["target_label"] : null,
        "client_id"    => $r["client_id"] !== null ? (int) $r["client_id"] : null,
        "summary"      => (string) $r["summary"],
        "created_at"   => (string) $r["created_at"],
        "created_ts"   => isset($r["created_ts"]) ? (int) $r["created_ts"] : null,
    ];
}
