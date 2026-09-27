<?php

require_once "db.php";

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
| A client's team
|--------------------------------------------------------------------------
|
| Who works on a client: the Team section of user-details.html. Being on a
| client's team is a row in admin_user_assignments, the same table every list
| in the admin scopes by (api/admin-users.php, admin-surveys.php, calendar.php,
| projects.php ...) - so adding someone here is what makes the client show up
| for them everywhere else.
|
| Three columns, and who may change each one:
|
|   owner            Account Managers, Super Admins, Admins - anyone
|   account_manager  Super Admins and Admins - from their own team only
|   super_admin      Admins - from their own team only
|   seo_admin        nothing; reads the table
|
| "Their own team" is admins.managed_by_admin_id, which the owner sets under
| Settings > Owner Assignments to Team Management. An account manager's team
| is the super admins handed to them, plus the admins handed to them or to one
| of those super admins.
|
*/

const TEAM_ROLES = ["account_manager", "super_admin", "seo_admin"];

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
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$actor) {
        respond(false, "Unauthorized.", [], 401);
    }
} catch (Throwable $e) {
    respond(false, "Authentication database error.", [], 500);
}

$actorId = (int) $actor["id"];
$isOwner = $actor["role"] === "owner";

/*
| Everyone the actor may add to or remove from a team, keyed by id. Removing
| follows the same pool as adding, so an account manager cannot take an admin
| belonging to someone else's team off a client.
*/
function teamPoolFor(PDO $pdo, array $actor)
{
    $actorId = (int) $actor["id"];

    if ($actor["role"] === "owner") {
        $stmt = $pdo->query("
            SELECT id, name, role, active
            FROM admins
            WHERE role IN ('account_manager', 'super_admin', 'seo_admin')
        ");

    } elseif ($actor["role"] === "account_manager") {
        $stmt = $pdo->prepare("
            SELECT id, name, role, active
            FROM admins
            WHERE (role = 'super_admin' AND managed_by_admin_id = ?)
               OR (role = 'seo_admin' AND (
                       managed_by_admin_id = ?
                    OR managed_by_admin_id IN (
                           SELECT sa.id FROM admins sa
                           WHERE sa.role = 'super_admin' AND sa.managed_by_admin_id = ?
                       )
                  ))
        ");
        $stmt->execute([$actorId, $actorId, $actorId]);

    } elseif ($actor["role"] === "super_admin") {
        $stmt = $pdo->prepare("
            SELECT id, name, role, active
            FROM admins
            WHERE role = 'seo_admin' AND managed_by_admin_id = ?
        ");
        $stmt->execute([$actorId]);

    } else {
        return [];
    }

    $pool = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pool[(int) $row["id"]] = $row;
    }

    return $pool;
}

/* Which columns the actor may change at all - the pool above says who. */
function editableColumnsFor($role)
{
    return [
        "account_manager" => $role === "owner",
        "super_admin"     => in_array($role, ["owner", "account_manager"], true),
        "seo_admin"       => in_array($role, ["owner", "account_manager", "super_admin"], true),
    ];
}

function teamMembers(PDO $pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.role
        FROM admin_user_assignments aua
        JOIN admins a ON a.id = aua.admin_id
        WHERE aua.user_id = ?
          AND a.role IN ('account_manager', 'super_admin', 'seo_admin')
        ORDER BY a.name ASC
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* The whole panel in one payload, so a POST can hand back exactly what a GET
   would and the page repaints from it without asking again. */
function teamPayload(PDO $pdo, array $actor, $userId)
{
    $editable = editableColumnsFor($actor["role"]);
    $pool = teamPoolFor($pdo, $actor);
    $members = teamMembers($pdo, $userId);

    $team = ["account_manager" => [], "super_admin" => [], "seo_admin" => []];
    $memberIds = [];
    $removable = [];

    foreach ($members as $member) {
        $id = (int) $member["id"];
        $memberIds[$id] = true;

        $team[$member["role"]][] = ["id" => $id, "name" => $member["name"]];

        if (!empty($editable[$member["role"]]) && isset($pool[$id])) {
            $removable[] = $id;
        }
    }

    $candidates = ["account_manager" => [], "super_admin" => [], "seo_admin" => []];

    foreach ($pool as $id => $admin) {
        $role = $admin["role"];

        // A deactivated account cannot log in, so it is not offered - but
        // one already on the team can still be taken off it.
        $active = !isset($admin["active"]) || (int) $admin["active"] === 1;

        if (empty($editable[$role]) || isset($memberIds[$id]) || !$active) {
            continue;
        }

        $candidates[$role][] = ["id" => $id, "name" => $admin["name"]];
    }

    foreach ($candidates as &$list) {
        usort($list, function ($a, $b) {
            return strcasecmp($a["name"], $b["name"]);
        });
    }
    unset($list);

    return [
        "team"       => $team,
        "editable"   => $editable,
        "candidates" => $candidates,
        "removable"  => $removable,
    ];
}

/*
|--------------------------------------------------------------------------
| Which client, and may this admin see it
|--------------------------------------------------------------------------
*/

$input = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $userId = (int) ($input["user_id"] ?? 0);
} else {
    $userId = (int) ($_GET["user_id"] ?? 0);
}

if (!$userId) {
    respond(false, "A numeric user id is required.", [], 400);
}

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $check->execute([$userId]);

    if (!$check->fetch()) {
        respond(false, "User not found.", [], 404);
    }

    // The owner sees every client; anyone else only the ones they are on the
    // team of - the same scope as the client list.
    if (!$isOwner) {
        $mine = $pdo->prepare("
            SELECT 1 FROM admin_user_assignments
            WHERE admin_id = ? AND user_id = ?
            LIMIT 1
        ");
        $mine->execute([$actorId, $userId]);

        if (!$mine->fetch()) {
            respond(false, "You are not on this client's team.", [], 403);
        }
    }
} catch (Throwable $e) {
    respond(false, "Could not load the team.", [], 500);
}

/*
|--------------------------------------------------------------------------
| Reading the team
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    try {
        respond(true, "Team loaded.", teamPayload($pdo, $actor, $userId));
    } catch (Throwable $e) {
        respond(false, "Could not load the team.", [], 500);
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request.", [], 405);
}

/*
|--------------------------------------------------------------------------
| Adding or removing one person
|--------------------------------------------------------------------------
*/

$action = $input["action"] ?? "";
$targetId = (int) ($input["admin_id"] ?? 0);

if (!in_array($action, ["add", "remove"], true)) {
    respond(false, "Unknown action.", [], 400);
}

if (!$targetId) {
    respond(false, "Pick someone first.", [], 400);
}

try {
    $pool = teamPoolFor($pdo, $actor);
    $editable = editableColumnsFor($actor["role"]);

    $target = $pool[$targetId] ?? null;

    if (!$target || empty($editable[$target["role"]])) {
        respond(false, "You can't change that person on this client's team.", [], 403);
    }

    $exists = $pdo->prepare("
        SELECT 1 FROM admin_user_assignments
        WHERE admin_id = ? AND user_id = ?
        LIMIT 1
    ");
    $exists->execute([$targetId, $userId]);
    $isMember = (bool) $exists->fetch();

    if ($action === "add") {

        if (isset($target["active"]) && (int) $target["active"] !== 1) {
            respond(false, "That account is deactivated.", [], 400);
        }

        // The table's keys are not known here, so a second row for the same
        // pair is avoided by checking rather than by relying on a constraint.
        if (!$isMember) {
            $pdo->prepare("INSERT INTO admin_user_assignments (admin_id, user_id) VALUES (?, ?)")
                ->execute([$targetId, $userId]);
        }

        $message = $target["name"] . " added to the team.";

    } else {

        $pdo->prepare("DELETE FROM admin_user_assignments WHERE admin_id = ? AND user_id = ?")
            ->execute([$targetId, $userId]);

        $message = $target["name"] . " removed from the team.";
    }

    respond(true, $message, teamPayload($pdo, $actor, $userId));

} catch (Throwable $e) {
    respond(false, "Could not save the team.", [], 500);
}
