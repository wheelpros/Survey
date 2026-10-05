<?php

/*
|--------------------------------------------------------------------------
| Who is calling, and what may they do - one answer for every endpoint
|--------------------------------------------------------------------------
|
| Every browser endpoint today repeats its own token lookup and role check
| (`SELECT ... FROM admins WHERE session_token = ?` and an if on `role`).
| This file is that logic once, and it also understands the second kind of
| credential: an access token an AI assistant got through OAuth for the
| W|ZONE MCP server (api/oauth/*).
|
| A principal is the person behind a request, however they authenticated:
|
|   [
|     "kind"   => "admin" | "user",
|     "id"     => admins.id | users.id,
|     "name", "email",
|     "role"   => admins.role, or "client" for a user,
|     "inquiries_access" => bool,            // admins only
|     "company_name"     => string|null,     // users only
|     "via"    => "session" | "mcp",
|     "scopes" => [...],    // a session may do whatever its role may; an MCP
|                           // token only what was consented to AND the role
|                           // still allows today
|     "grant_id", "client_id", "client_name"  // via mcp only
|   ]
|
| api/v1/ (the AI-facing API) uses this from the start. The existing browser
| endpoints keep their own checks for now and can move onto this one at a
| time. Requires db.php ($pdo) and api/oauth/lib.php for the token tables.
*/

require_once __DIR__ . "/oauth/lib.php";

// The four admin roles the portal knows (see api/admin-users.php).
const ADMIN_ROLES = ["owner", "super_admin", "seo_admin", "account_manager"];

/**
 * The raw bearer credential on this request, or "".
 */
function bearerToken()
{
    $header = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
    if ($header === "" && function_exists("getallheaders")) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, "Authorization") === 0) {
                $header = $value;
            }
        }
    }
    return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string) $header, $m) ? $m[1] : "";
}

/*
|--------------------------------------------------------------------------
| Which roles may hold which scope
|--------------------------------------------------------------------------
|
| Exactly the role rules the browser endpoints already enforce - so the
| consent page never offers something the portal would then refuse, and an
| AI can never be given more than its person has in the browser:
|
|   clients:read     all       admin-users.php (every role reaches the list)
|   clients:write    O, AM     admin-user-details.php $canManageNotes
|   forms:read       all       admin-surveys.php (own forms; reviewers see all)
|   forms:write      all       admin-surveys.php POST - drafts, scoped to the
|                              admin's clients, still go through review
|   forms:review     O, AM     admin-survey-review.php $canReview
|   inquiries:read   O, AM*    admin-inquiries.php - *with inquiries_access
|   inquiries:write  O         admin-inquiries.php create / edit / delete
|   content:read     all       admin-content.php (own posts; O and AM see all)
|   content:write    all       admin-content.php - non-publishers are forced
|                              to draft, and nothing here publishes
|   calendar:*       O, SA, AM calendar.php $calendarRoles - closed to seo_admin
|   projects:*       all       projects.php / project-tasks.php - seo_admin
|                              only on projects they are a member of, which
|                              the endpoint checks per project
|   notifications:*  all       notifications.php
|
| O owner, SA super_admin, AM account_manager. Which *records* a role sees
| within a scope (assigned clients, own posts, project membership) is the
| endpoint's job, the same way it is in the browser - see assignedClientIds().
|
| When a browser endpoint's role rule changes, change it here too.
*/
const OWNER_AND_AM = ["owner", "account_manager"];
const CALENDAR_ROLES = ["owner", "super_admin", "account_manager"];

const SCOPE_ROLES = [
    "clients:read"        => ADMIN_ROLES,
    "clients:write"       => OWNER_AND_AM,
    "forms:read"          => ADMIN_ROLES,
    "forms:write"         => ADMIN_ROLES,
    "forms:review"        => OWNER_AND_AM,
    "inquiries:read"      => OWNER_AND_AM,   // plus inquiries_access for AM, below
    "inquiries:write"     => ["owner"],
    "content:read"        => ADMIN_ROLES,
    "content:write"       => ADMIN_ROLES,
    "calendar:read"       => CALENDAR_ROLES,
    "calendar:write"      => CALENDAR_ROLES,
    "projects:read"       => ADMIN_ROLES,
    "projects:write"      => ADMIN_ROLES,
    "notifications:read"  => ADMIN_ROLES,
    "notifications:write" => ADMIN_ROLES,
];

/**
 * The scopes a person's role allows at all - the ceiling for any MCP grant,
 * checked again on every request so a role change takes effect at once.
 */
function scopesAllowedFor(PDO $pdo, array $p)
{
    if ($p["kind"] === "user") {
        return clientsMayUseMcp($pdo) ? CLIENT_SCOPES : [];
    }

    $role = $p["role"];
    if (!in_array($role, ADMIN_ROLES, true)) {
        return [];
    }

    return array_values(array_filter(STAFF_SCOPES, function ($scope) use ($role, $p) {
        if (!in_array($role, SCOPE_ROLES[$scope] ?? [], true)) {
            return false;
        }
        // An account manager reads inquiries only once the owner switched
        // it on for them (api/inquiries-access.php).
        if ($scope === "inquiries:read" && $role === "account_manager") {
            return !empty($p["inquiries_access"]);
        }
        return true;
    }));
}

function principalFromAdminRow(array $row)
{
    return [
        "kind" => "admin",
        "id" => (int) $row["id"],
        "name" => $row["name"] ?? "",
        "email" => $row["email"] ?? "",
        "role" => $row["role"] ?? "",
        "inquiries_access" => !empty($row["inquiries_access"]),
        "company_name" => null,
    ];
}

function principalFromUserRow(array $row)
{
    return [
        "kind" => "user",
        "id" => (int) $row["id"],
        "name" => $row["name"] ?? "",
        "email" => $row["email"] ?? "",
        "role" => "client",
        "inquiries_access" => false,
        "company_name" => $row["company_name"] ?? null,
    ];
}

/**
 * Loads a principal's current row - an admin who still exists and is not
 * deactivated, or a client who is still approved - or null.
 */
function loadPrincipal(PDO $pdo, $kind, $id)
{
    if ($kind === "admin") {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!$row || (isset($row["active"]) && (int) $row["active"] === 0)) {
            return null;
        }
        return principalFromAdminRow($row);
    }

    if ($kind === "user") {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!$row || (int) ($row["approved"] ?? 0) !== 1) {
            return null;
        }
        return principalFromUserRow($row);
    }

    return null;
}

/**
 * The principal behind a browser session token, or null. "preview" is the
 * demo login's placeholder and never a real session.
 */
function principalFromSessionToken(PDO $pdo, $token)
{
    if ($token === "" || $token === "preview" || strlen($token) > 255) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        if (isset($row["active"]) && (int) $row["active"] === 0) {
            return null;
        }
        $p = principalFromAdminRow($row);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE session_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row || (int) ($row["approved"] ?? 0) !== 1) {
            return null;
        }
        $p = principalFromUserRow($row);
    }

    $p["via"] = "session";
    $p["scopes"] = scopesAllowedFor($pdo, $p);
    return $p;
}

/**
 * The principal behind this request, or null. Accepts a browser session
 * token or an MCP access token, never anything else.
 */
function currentPrincipal(PDO $pdo)
{
    static $resolved = false;
    static $principal = null;
    if ($resolved) {
        return $principal;
    }
    $resolved = true;

    $token = bearerToken();
    if ($token === "") {
        return null;
    }

    if (isMcpAccessTokenShape($token)) {
        $principal = principalFromMcpToken($pdo, $token);
    } else {
        $principal = principalFromSessionToken($pdo, $token);
    }
    return $principal;
}

/**
 * The principal behind an MCP access token: the token must be live, its
 * grant not revoked, and the person still active - and the scopes are what
 * was consented to, cut down to what the role allows today.
 */
function principalFromMcpToken(PDO $pdo, $token)
{
    $row = findLiveMcpToken($pdo, $token, "access");
    if (!$row) {
        return null;
    }

    $p = loadPrincipal($pdo, $row["principal_kind"], $row["principal_id"]);
    if (!$p) {
        return null;
    }

    $granted = scopeList($row["scopes"]);
    $p["via"] = "mcp";
    $p["scopes"] = array_values(array_intersect($granted, scopesAllowedFor($pdo, $p)));
    $p["grant_id"] = (int) $row["grant_id"];
    $p["client_id"] = $row["client_id"];
    $p["client_name"] = $row["client_name"] ?? null;
    $p["expires_at"] = (int) $row["expires_at"];
    $p["resource"] = $row["resource"];
    return $p;
}

function hasScope(array $p, $scope)
{
    return in_array($scope, $p["scopes"], true);
}

/**
 * The client (users.id) list an admin may see: null for the owner, meaning
 * every client; otherwise the ids assigned to them. The same rule every
 * admin list applies (api/admin-users.php, calendar.php, projects.php...).
 */
function assignedClientIds(PDO $pdo, array $p)
{
    if ($p["kind"] !== "admin") {
        return [];
    }
    if ($p["role"] === "owner") {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM admin_user_assignments WHERE admin_id = ?");
        $stmt->execute([$p["id"]]);
        return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

function canSeeClient(PDO $pdo, array $p, $userId)
{
    if ($p["kind"] === "user") {
        return (int) $userId === $p["id"];
    }
    $ids = assignedClientIds($pdo, $p);
    return $ids === null || in_array((int) $userId, $ids, true);
}
