<?php

/*
|--------------------------------------------------------------------------
| Data scope: which records an admin may see
|--------------------------------------------------------------------------
|
| A permission says what someone may do (api/permissions.php). This file says
| which records they may do it to. The level never comes from a role name:
| it is "all" for the holder of the resource's *.view_all-style key, and
| otherwise the narrower rule the resource has always had.
|
|   dataScope($pdo, $p, "response")                    "all", "assigned", "own" or "none"
|   [$sql, $params] = scopeSql($pdo, $p, "response", "sr")
|   canAccessRecord($pdo, $p, "response", $id)        true or false
|
| scopeSql() returns ["", []] when every record is visible, so it drops
| straight into a WHERE built from parts. A record outside the scope is
| answered as not found (404), the same as a missing one, so ids can't be
| probed for existence.
|
| The resources, the key that lifts the limit, and the narrower rule:
|
|   client    clients.view_all   the clients assigned to them (admin_user_assignments)
|   response  forms.responses    answers from those clients, or to a form they built
|   form      forms.review       the forms they built
|   content   content.view_all   the posts they created or are assigned to
|   project   projects.view_all  their clients' projects, the ones they manage
|                                or are a member of
|
| Activity reports, team forms and messages follow the management tree and
| their own participants - activity-report-lib.php, team-form-writes.php and
| admin-messages.php keep those rules. There is no department scope: a
| department is a label and a filter, and grants nothing.
|
*/

require_once __DIR__ . "/auth.php";

const DATA_SCOPES = [
    "client"   => ["key" => "clients.view_all",  "narrow" => "assigned", "table" => "users",            "alias" => "u"],
    "response" => ["key" => "forms.responses",   "narrow" => "assigned", "table" => "survey_responses", "alias" => "sr"],
    "form"     => ["key" => "forms.review",      "narrow" => "own",      "table" => "surveys",          "alias" => "s"],
    "content"  => ["key" => "content.view_all",  "narrow" => "own",      "table" => "content",          "alias" => "c"],
    "project"  => ["key" => "projects.view_all", "narrow" => "assigned", "table" => "projects",         "alias" => "p"],
];

function dataScopeDef($resource)
{
    if (!isset(DATA_SCOPES[$resource])) {
        throw new LogicException("Unknown data scope: $resource");
    }
    return DATA_SCOPES[$resource];
}

/**
 * How much of $resource $p sees: "all", the resource's narrower level, or
 * "none" for anyone who is not an active admin. Clients are never in scope
 * here - their own records are their endpoints' business.
 */
function dataScope(PDO $pdo, array $p, $resource)
{
    $def = dataScopeDef($resource);
    if (($p["kind"] ?? "") !== "admin") {
        return "none";
    }
    return can($p, $def["key"], $pdo) ? "all" : $def["narrow"];
}

/**
 * [sql, params] limiting `$alias` (a table of $resource's kind) to what $p
 * may see; ["", []] for everything. For "client", $alias may be any users.id
 * column, such as "sr.user_id".
 */
function scopeSql(PDO $pdo, array $p, $resource, $alias)
{
    $level = dataScope($pdo, $p, $resource);
    if ($level === "all") {
        return ["", []];
    }
    if ($level === "none") {
        return ["1 = 0", []];
    }

    $me = (int) $p["id"];
    switch ($resource) {
        case "client":
            $column = strpos($alias, ".") === false ? "$alias.id" : $alias;
            return ["$column IN (SELECT user_id FROM admin_user_assignments WHERE admin_id = ?)", [$me]];

        case "response":
            return ["(
                $alias.user_id IN (SELECT user_id FROM admin_user_assignments WHERE admin_id = ?)
                OR $alias.survey_id IN (SELECT id FROM surveys WHERE created_by_admin_id = ?)
            )", [$me, $me]];

        case "form":
            return ["$alias.created_by_admin_id = ?", [$me]];

        case "content":
            return contentVisibleSql($alias, $me);

        case "project":
            [$sql, $params] = projectScopeSql($p);
            // projectScopeSql() is written against `projects p`.
            return [$alias === "p" ? $sql : preg_replace('/\bp\./', "$alias.", $sql), $params];
    }
    throw new LogicException("No scope rule for $resource");
}

/** Whether $p may see the $resource record with this id. A missing record is a no. */
function canAccessRecord(PDO $pdo, array $p, $resource, $id)
{
    $def = dataScopeDef($resource);
    $alias = $def["alias"];
    [$sql, $params] = scopeSql($pdo, $p, $resource, $alias);

    $stmt = $pdo->prepare("SELECT 1 FROM {$def["table"]} $alias WHERE $alias.id = ?"
        . ($sql !== "" ? " AND $sql" : "") . " LIMIT 1");
    $stmt->execute(array_merge([(int) $id], $params));
    return (bool) $stmt->fetchColumn();
}

/** canAccessRecord(), or the request ends with a 404 in the browser's JSON shape. */
function requireRecord(PDO $pdo, array $p, $resource, $id, $message = "Not found")
{
    if (!canAccessRecord($pdo, $p, $resource, $id)) {
        denyRequest(404, $message);
    }
}
