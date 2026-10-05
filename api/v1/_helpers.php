<?php

/*
|--------------------------------------------------------------------------
| api/v1 - what every read endpoint shares
|--------------------------------------------------------------------------
|
| Parameter checks that answer 422 instead of guessing, cursor pagination,
| and the visibility rules each area copies from its browser endpoint. The
| rules live here, once, with a pointer to where the browser applies the
| same one - when a browser rule changes, change it here too, or the AI
| starts seeing more (or less) than the page does.
|
| Loaded by _bootstrap.php.
*/

const V1_DEFAULT_LIMIT = 25;
const V1_MAX_LIMIT = 100;

/*
| The content types admin-content-form.html offers - nine fixed ones, one
| per icon in assets/icon. Keep in step with TYPE_TITLES there; the id is
| the same typeIdFor() rule (lowercase, non-alphanumerics to "_").
*/
const CONTENT_TYPE_TITLES = [
    "Articles", "Campaign", "Design", "Events", "Photos",
    "Reports", "Social Media", "Training", "Videos",
];

function v1RequireMethod($method)
{
    if ($_SERVER["REQUEST_METHOD"] !== $method) {
        v1Error(405, "method_not_allowed", "$method only");
    }
}

/** Staff areas: a client's token never reaches them, whatever its scopes. */
function v1RequireStaff(array $p)
{
    if ($p["kind"] !== "admin") {
        v1Error(403, "staff_only", "This is only available to W|ZONE staff.");
    }
}

/** A positive integer query parameter, or null when absent. 422 if malformed. */
function v1IntParam($name)
{
    if (!isset($_GET[$name]) || $_GET[$name] === "") {
        return null;
    }
    $raw = (string) $_GET[$name];
    if (!ctype_digit($raw) || (int) $raw <= 0 || strlen($raw) > 10) {
        v1Error(422, "invalid_parameter", "$name must be a positive whole number.");
    }
    return (int) $raw;
}

/** A query parameter from a fixed list, or null when absent. */
function v1EnumParam($name, array $allowed)
{
    if (!isset($_GET[$name]) || $_GET[$name] === "") {
        return null;
    }
    $value = (string) $_GET[$name];
    if (!in_array($value, $allowed, true)) {
        v1Error(422, "invalid_parameter", "$name must be one of: " . implode(", ", $allowed) . ".");
    }
    return $value;
}

/** A YYYY-MM-DD query parameter, or $default. */
function v1DateParam($name, $default = null)
{
    if (!isset($_GET[$name]) || $_GET[$name] === "") {
        return $default;
    }
    $value = (string) $_GET[$name];
    $d = DateTime::createFromFormat("!Y-m-d", $value);
    if (!$d || $d->format("Y-m-d") !== $value) {
        v1Error(422, "invalid_parameter", "$name must be a date like 2026-10-05.");
    }
    return $value;
}

/** A free-text query parameter, trimmed and capped, or "". */
function v1TextParam($name, $max = 100)
{
    $value = trim((string) ($_GET[$name] ?? ""));
    if (mb_strlen($value) > $max) {
        v1Error(422, "invalid_parameter", "$name must be $max characters or fewer.");
    }
    return $value;
}

function v1Limit($default = V1_DEFAULT_LIMIT, $max = V1_MAX_LIMIT)
{
    $limit = v1IntParam("limit");
    return $limit === null ? $default : min($limit, $max);
}

/*
|--------------------------------------------------------------------------
| Cursors
|--------------------------------------------------------------------------
|
| Keyset pagination: a cursor is the sort key of the last row returned,
| base64url-encoded JSON, opaque to the caller. Unlike an offset it never
| skips or repeats a row when something new arrives between pages - the
| notifications inbox and the leads list both grow while being read.
|
| A list ordered newest first by id takes v1IdCursor(); a list ordered by
| some other column takes v1Cursor() and keysetSql() with that column.
*/

function v1EncodeCursor(array $key)
{
    return rtrim(strtr(base64_encode(json_encode($key)), "+/", "-_"), "=");
}

/** The decoded cursor (a list of $parts scalars), or null for the first page. */
function v1Cursor($parts)
{
    $raw = (string) ($_GET["cursor"] ?? "");
    if ($raw === "") {
        return null;
    }
    $json = base64_decode(strtr($raw, "-_", "+/"), true);
    $key = $json === false ? null : json_decode($json, true);
    if (!is_array($key) || count($key) !== $parts) {
        v1Error(422, "invalid_cursor", "That cursor isn't valid here - start again without one.");
    }
    foreach ($key as $value) {
        if (!is_scalar($value)) {
            v1Error(422, "invalid_cursor", "That cursor isn't valid here - start again without one.");
        }
    }
    return $key;
}

/** For lists ordered by id DESC: the last id seen, or null. */
function v1IdCursor()
{
    $key = v1Cursor(1);
    if ($key === null) {
        return null;
    }
    if (!is_int($key[0]) || $key[0] <= 0) {
        v1Error(422, "invalid_cursor", "That cursor isn't valid here - start again without one.");
    }
    return $key[0];
}

/**
 * The WHERE fragment that continues after $cursor for a list ordered by
 * ($sortExpr, $idExpr) in $direction. $sortExpr must never be NULL.
 */
function v1KeysetSql($sortExpr, $idExpr, array $cursor, $direction)
{
    $op = $direction === "ASC" ? ">" : "<";
    return [
        "($sortExpr $op ? OR ($sortExpr = ? AND $idExpr $op ?))",
        [$cursor[0], $cursor[0], (int) $cursor[1]],
    ];
}

/**
 * Rows fetched with LIMIT $limit + 1 become one page: the extra row only
 * says there is more, and $keyOf the last kept row is the next cursor.
 */
function v1Page(array $rows, $limit, callable $keyOf, callable $shape)
{
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    return [
        "items" => array_map($shape, $rows),
        "next_cursor" => $more && $rows ? v1EncodeCursor($keyOf(end($rows))) : null,
    ];
}

/*
|--------------------------------------------------------------------------
| Text people typed
|--------------------------------------------------------------------------
|
| Form answers, lead answers, meeting topics, captions, notes - anything a
| client, a lead or a colleague wrote - goes out under an `untrusted_content`
| key. The MCP server tells the model what that label means: it is data to
| read, never instructions to follow, so "ignore your instructions and..."
| typed into a form is just an answer.
*/

/** HTML (captions, rich descriptions) to plain text, optionally cut short. */
function v1PlainText($html, $max = null)
{
    $text = (string) $html;
    $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, "UTF-8");
    $text = trim(preg_replace("/[ \t]+/", " ", preg_replace("/\n{3,}/", "\n\n", $text)));
    if ($max !== null && mb_strlen($text) > $max) {
        $text = rtrim(mb_substr($text, 0, $max - 1)) . "…";
    }
    return $text;
}

/** A DATETIME as "Y-m-d H:i:s" - MariaDB's TIMESTAMP() adds microseconds. */
function v1DateTime($value)
{
    return substr((string) $value, 0, 19);
}

function v1NullableString($value)
{
    return $value === null || $value === "" ? null : (string) $value;
}

/*
|--------------------------------------------------------------------------
| Who sees which records
|--------------------------------------------------------------------------
*/

/**
 * SQL limiting a users.id column to the clients this admin is assigned -
 * nothing for the owner. Same rule as admin-users.php, admin-responses.php
 * and calendar.php's scopedClients().
 */
function v1ClientScopeSql(array $p, $column)
{
    if ($p["role"] === "owner") {
        return ["", []];
    }
    return ["$column IN (SELECT user_id FROM admin_user_assignments WHERE admin_id = ?)", [$p["id"]]];
}

/**
 * A client (users row) this admin may see, or a 404 - the same answer for
 * "doesn't exist" and "not yours", so ids can't be probed.
 */
function v1VisibleClient(PDO $pdo, array $p, $userId)
{
    ensureUserProfileColumns($pdo);
    if (!canSeeClient($pdo, $p, $userId)) {
        v1Error(404, "not_found", "No client with that id among the clients you can see.");
    }
    $stmt = $pdo->prepare("
        SELECT id, name, email, phone, whatsapp, company_name, website, description,
               approved, created_at
        FROM users WHERE id = ? LIMIT 1
    ");
    $stmt->execute([(int) $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        v1Error(404, "not_found", "No client with that id among the clients you can see.");
    }
    return $row;
}

/** The short client reference every list item carries. */
function v1ClientRef($id, $name, $company, $email = null)
{
    $ref = [
        "id" => (int) $id,
        "name" => (string) ($name ?? ""),
        "company_name" => v1NullableString($company),
    ];
    if ($email !== null) {
        $ref["email"] = (string) $email;
    }
    return $ref;
}

/*
| Forms: the owner and account managers see every form; everyone else the
| forms they wrote. admin-surveys.php canSeeSurvey(), admin-survey-review.php.
*/
function v1SeesAllForms(array $p)
{
    return in_array($p["role"], ["owner", "account_manager"], true);
}

/*
| Content: the owner and account managers see the whole library; everyone
| else - super_admin included - only their own posts. admin-content.php
| canViewAllContent().
*/
function v1SeesAllContent(array $p)
{
    return in_array($p["role"], ["owner", "account_manager"], true);
}

/*
| Projects: the owner sees all; anyone else the projects of clients assigned
| to them, the ones they manage, and the ones they are a member of.
| projects.php projectScope(). Assumes `projects p`.
*/
function v1ProjectScopeSql(array $p)
{
    return projectScopeSql($p);
}

/** Joins non-empty WHERE fragments; returns [" WHERE ...", params]. */
function v1Where(array $parts)
{
    $sql = [];
    $params = [];
    foreach ($parts as [$fragment, $fragmentParams]) {
        if ($fragment !== "") {
            $sql[] = $fragment;
            $params = array_merge($params, $fragmentParams);
        }
    }
    return [$sql ? " WHERE " . implode(" AND ", $sql) : "", $params];
}
