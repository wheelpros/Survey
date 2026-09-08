<?php

/*
|--------------------------------------------------------------------------
| Projects, as the client sees them
|--------------------------------------------------------------------------
|
| Backs user-projects.html (the list) and user-project-details.html (one
| project with its live task updates). Read-only: there is no POST, PUT or
| DELETE branch, and nothing here writes a row.
|
| One file rather than the admin side's two. That split exists because tasks
| own an upload directory, a size cap and a set of write gates; none of that
| survives read-only, so the whole client surface is two SELECT shapes told
| apart by the presence of ?id= - the same way api/projects.php's GET branch
| routes.
|
| Everything it needs from the module - PROJECT_TYPES, PROJECT_STATUSES and
| TASK_IS_LIVE_SQL - comes from db.php. The admin endpoints cannot be
| require_once'd: each runs its auth block at top level and exits.
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper: JSON Response
|--------------------------------------------------------------------------
|
| response() from api/project-tasks.php, with user-content.php's rule laid
| over it: every failure path still carries the data key its caller reads
| ("projects" => [], or "project" => null with "tasks" => []), so neither page
| ever has to null-check a payload it already asked for.
|
*/

function response($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* The two payload shapes, empty. A failure answers with the one its caller
   asked for, so no page has to guess which keys came back. */
const EMPTY_LIST   = ["projects" => []];
const EMPTY_DETAIL = ["project" => null, "tasks" => []];

/*
|--------------------------------------------------------------------------
| Authenticate the portal user
|--------------------------------------------------------------------------
|
| Lifted from api/user-content.php: the token must match a users row and the
| account must be approved. A real 401 rather than a 200 carrying an error -
| that status is what the pages' stale-session branch keys off, the same way
| content.html does.
|
| An admins.session_token cannot satisfy a query that looks only in `users`,
| so an admin token gets a clean 401 here without needing a rule of its own.
|
| Only id and approved are selected. company_name is the client's own name and
| neither page prints it.
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];

$authorization =
    $headers["Authorization"]
    ?? $headers["authorization"]
    ?? $_SERVER["HTTP_AUTHORIZATION"]
    ?? "";

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
    response(false, "Unauthorized access.", EMPTY_LIST, 401);
}

$token = trim($matches[1]);

try {

    $stmt = $pdo->prepare("
        SELECT id, approved
        FROM users
        WHERE session_token = ?
        LIMIT 1
    ");

    $stmt->execute([$token]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int) $user["approved"] !== 1) {
        response(false, "Invalid session token.", EMPTY_LIST, 401);
    }

} catch (Throwable $e) {
    response(false, "Authentication database error.", EMPTY_LIST, 500);
}

$clientId = (int) $user["id"];

ensureProjectTables($pdo);
ensureNotificationsTable($pdo);

/*
|--------------------------------------------------------------------------
| Scope - one predicate, four call sites
|--------------------------------------------------------------------------
|
| The whole of this file's authority. api/projects.php has three ways in
| because an admin reaches a project several ways; a client reaches theirs
| exactly one way, and the id comes from the session token, never from the
| query string.
|
| Bound into all four queries - list, stats, single project, tasks. The task
| read re-asserts it through its own join rather than trusting that the
| project read already passed: two queries that each carry the check cannot be
| reordered into a hole.
|
*/

const CLIENT_SCOPE_SQL = "p.client_id = ?";

/* Out of scope and non-existent are the same answer, deliberately. A 403 on
   one and a 404 on the other would confirm the row exists and turn ?id= into
   an enumeration oracle over the customer base - the house rule both admin
   endpoints already state in comments. */
const NOT_FOUND_MESSAGE = "Project not found.";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, "Unsupported request method.", EMPTY_LIST, 405);
}

/*
|--------------------------------------------------------------------------
| GET api/user-projects.php?id=42 - one project and its live updates
|--------------------------------------------------------------------------
|
| The month grid, the three tiles, the task cards, their pager and the View
| panel are all painted from this one payload. Paging the month is pure client
| state, so there is no ?month= endpoint.
|
| Columns are listed out rather than taken as p.* / t.*, which is the whole
| leak audit in one habit: the admin reads bolt seven joined staff names and
| emails onto a p.*, plus a task_count that counts drafts. This file joins
| neither `users` nor `admins`, so it cannot carry a staff name structurally
| rather than by discipline.
|
*/

if (isset($_GET["id"])) {

    try {

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.title,
                p.description,
                p.project_type,
                p.start_date,
                p.end_date,
                p.progress,
                p.status,
                p.image_path
            FROM projects p
            WHERE p.id = ?
              AND " . CLIENT_SCOPE_SQL . "
            LIMIT 1
        ");

        $stmt->execute([(int) $_GET["id"], $clientId]);

        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            response(false, NOT_FOUND_MESSAGE, EMPTY_DETAIL, 404);
        }

        $project["type_label"]   = PROJECT_TYPES[$project["project_type"]] ?? $project["project_type"];
        $project["status_label"] = PROJECT_STATUSES[$project["status"]] ?? $project["status"];

        /*
        | TASK_IS_LIVE_SQL applies in exactly one place in this file, and this
        | is it. On the admin side it is a column - the grid shows drafts and
        | future-dated tasks and badges them. Here it is a filter: a task that
        | is not live does not exist.
        |
        | `status` and `is_live` are therefore not returned. They would be
        | constants after this WHERE, and returning a constant invites a dead
        | DRAFT branch on the page that fires the day somebody re-adds it.
        |
        | Ordering is byte-identical to the admin's so the two views agree.
        */

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.title,
                t.link,
                t.description,
                t.orientation,
                t.image_path,
                t.scheduled_date,
                t.scheduled_time,
                t.is_complete
            FROM project_tasks t
            INNER JOIN projects p
                ON p.id = t.project_id
               AND " . CLIENT_SCOPE_SQL . "
            WHERE t.project_id = ?
              AND " . TASK_IS_LIVE_SQL . "
            ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id DESC
        ");

        $stmt->execute([$clientId, (int) $project["id"]]);

        response(true, "Project loaded.", [
            "project" => $project,
            "tasks"   => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);

    } catch (Throwable $e) {
        response(false, "Could not load the project.", EMPTY_DETAIL, 500);
    }
}

/*
|--------------------------------------------------------------------------
| GET api/user-projects.php - the list, its stats and the type filter
|--------------------------------------------------------------------------
|
| Returned unpaged, the way every list in this portal is: the page slices it
| with PAGE_SIZE 4. The filters are accepted server-side anyway so that adding
| a LIMIT later is additive rather than a rewrite.
|
| `search` matches p.title only. The admin's company_name and name clauses are
| the caller's own name on every row here, so they would match everything or
| nothing. `client_id` is never accepted from anywhere.
|
*/

try {

    $where  = [CLIENT_SCOPE_SQL];
    $params = [$clientId];

    $search = trim((string) ($_GET["search"] ?? ""));

    if ($search !== "") {
        $where[]  = "p.title LIKE ?";
        $params[] = "%" . $search . "%";
    }

    if (!empty($_GET["type"]) && isset(PROJECT_TYPES[$_GET["type"]])) {
        $where[]  = "p.project_type = ?";
        $params[] = $_GET["type"];
    }

    $whereSql = " WHERE " . implode(" AND ", $where);

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.description,
            p.project_type,
            p.start_date,
            p.end_date,
            p.progress,
            p.status,
            p.image_path
        FROM projects p
        $whereSql
        ORDER BY p.start_date DESC, p.id DESC
    ");

    $stmt->execute($params);

    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($projects as &$row) {
        $row["type_label"]   = PROJECT_TYPES[$row["project_type"]] ?? $row["project_type"];
        $row["status_label"] = PROJECT_STATUSES[$row["status"]] ?? $row["status"];
    }
    unset($row);

    /*
    | The same CASE arithmetic as the admin's cards with the scope swapped, and
    | the same deliberate rule: counted over the scope only, ignoring the
    | filter row above. The cards summarise everything being done for this
    | client; the footer under the table describes what the table is showing.
    | The two are meant to diverge once a filter is applied.
    |
    | The casts matter - SUM() is NULL over an empty set, and a client with no
    | projects at all is an ordinary first-run state, not an error.
    */

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                   AS total,
            SUM(p.status =  'completed')                               AS completed,
            SUM(p.status <> 'completed' AND p.start_date >  CURDATE()) AS upcoming,
            SUM(p.status <> 'completed' AND p.start_date <= CURDATE()) AS active
        FROM projects p
        WHERE " . CLIENT_SCOPE_SQL . "
    ");

    $stmt->execute([$clientId]);

    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    /* The type filter, and nothing else. No clients, no managers, no admins,
       no statuses - the admin's formOptions() hands back every other client
       and the whole staff directory. */
    $types = [];

    foreach (PROJECT_TYPES as $id => $label) {
        $types[] = ["id" => $id, "label" => $label];
    }

    response(true, "Projects loaded.", [
        "projects" => $projects,
        "stats"    => [
            "total"     => (int) ($stats["total"] ?? 0),
            "active"    => (int) ($stats["active"] ?? 0),
            "completed" => (int) ($stats["completed"] ?? 0),
            "upcoming"  => (int) ($stats["upcoming"] ?? 0),
        ],
        "types"    => $types,
    ]);

} catch (Throwable $e) {
    response(false, "Could not load projects.", EMPTY_LIST, 500);
}
