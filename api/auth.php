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
|     "company_name"     => string|null,     // users only
|     "via"    => "session" | "mcp",
|     "scopes" => [...],    // a session may do whatever its permissions allow;
|                           // an MCP token only what was consented to AND
|                           // the permissions still allow today
|     "grant_id", "client_id", "client_name"  // via mcp only
|   ]
|
| api/v1/ (the AI-facing API) uses this from the start. The existing browser
| endpoints keep their own checks for now and can move onto this one at a
| time. Requires db.php ($pdo) and api/oauth/lib.php for the token tables.
*/

require_once __DIR__ . "/oauth/lib.php";
require_once __DIR__ . "/permissions.php";

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
| Which permission each scope needs
|--------------------------------------------------------------------------
|
| A scope is offered to an AI only when its person holds the permission the
| browser endpoint checks for the same thing (api/permissions.php), so the
| consent page never offers something the portal would then refuse, and an
| AI can never be given more than its person has in the browser. null means
| any admin; which *records* they see within it is still the endpoint's job,
| the same way it is in the browser - see assignedClientIds().
|
| When the Director changes a permission, every MCP token follows on its
| next request: scopes are worked out again each time.
*/
const SCOPE_PERMISSIONS = [
    "clients:read"        => null,
    "clients:write"       => "clients.edit",
    "forms:read"          => null,
    "forms:write"         => null,
    "forms:review"        => "forms.review",
    "inquiries:read"      => "inquiries.read",
    "inquiries:write"     => "inquiries.write",
    "content:read"        => null,
    "content:write"       => "content.create",
    "calendar:read"       => "calendar.use",
    "calendar:write"      => "calendar.use",
    "projects:read"       => null,
    "projects:write"      => null,
    "notifications:read"  => null,
    "notifications:write" => null,
    "settings:read"       => "settings.manage",
    "settings:write"      => "settings.manage",
    "admins:write"        => "admins.manage",
    "events:read"         => "events.view",
];

/**
 * The scopes a person's permissions allow at all - the ceiling for any MCP
 * grant, checked again on every request so a change takes effect at once.
 */
function scopesAllowedFor(PDO $pdo, array $p)
{
    if ($p["kind"] === "user") {
        return clientsMayUseMcp($pdo) ? CLIENT_SCOPES : [];
    }

    if (!in_array($p["role"], ADMIN_ROLES, true)) {
        return [];
    }

    return array_values(array_filter(STAFF_SCOPES, function ($scope) use ($pdo, $p) {
        if (!array_key_exists($scope, SCOPE_PERMISSIONS)) {
            return false;
        }
        $perm = SCOPE_PERMISSIONS[$scope];
        return $perm === null || can($p, $perm, $pdo);
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
 * The client (users.id) list an admin may see: null for anyone holding
 * clients.view_all, meaning every client; otherwise the ids assigned to
 * them. The same rule every admin list applies (api/admin-users.php,
 * calendar.php, projects.php...).
 */
function assignedClientIds(PDO $pdo, array $p)
{
    if ($p["kind"] !== "admin") {
        return [];
    }
    if (can($p, "clients.view_all", $pdo)) {
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
