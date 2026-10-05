<?php

/*
| Clients - the people W|ZONE works for, as the calling admin sees them.
|
|   GET clients.php?query=&cursor=&limit=     search_clients
|   GET clients.php?id=N                      get_client_overview
|   GET clients.php?id=N&part=team            get_client_team
|   GET clients.php?id=N&part=note            get_my_client_note
|
| Scope clients:read. The owner sees every client, everyone else only the
| clients assigned to them (admin-users.php). A client outside that is a 404
| everywhere here, exactly like one that doesn't exist.
|
| The overview is one round trip on purpose: "tell me about client X" should
| not cost the model seven tool calls. Each of its sections is only filled
| when the connection also holds that area's read scope and follows that
| area's own visibility rule - the overview never shows more than the area
| tools would.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "clients:read");

ensureUserProfileColumns($pdo);

$id = v1IntParam("id");

if ($id === null) {
    $query = v1TextParam("query");
    $limit = v1Limit();
    $after = v1IdCursor();

    $parts = [v1ClientScopeSql($p, "u.id")];
    if ($query !== "") {
        $like = "%" . $query . "%";
        $parts[] = ["(u.name LIKE ? OR u.email LIKE ? OR u.company_name LIKE ?)", [$like, $like, $like]];
    }
    if ($after !== null) {
        $parts[] = ["u.id < ?", [$after]];
    }
    [$where, $params] = v1Where($parts);

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name, u.approved, u.created_at
        FROM users u
        $where
        ORDER BY u.id DESC
        LIMIT " . ($limit + 1)
    );
    $stmt->execute($params);

    v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
        return [(int) $r["id"]];
    }, function ($r) {
        return [
            "id" => (int) $r["id"],
            "name" => (string) $r["name"],
            "email" => (string) $r["email"],
            "company_name" => v1NullableString($r["company_name"]),
            // A registration the owner hasn't approved yet can't sign in.
            "approved" => (int) $r["approved"] === 1,
            "created_at" => (string) $r["created_at"],
        ];
    }));
}

$client = v1VisibleClient($pdo, $p, $id);
$ref = v1ClientRef($client["id"], $client["name"], $client["company_name"], $client["email"]);
$part = v1EnumParam("part", ["team", "note"]);

/* Who works on this client: client-team.php teamMembers(). The owner is on
   every client and so is not listed. */
function clientTeam(PDO $pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.email, a.role
        FROM admin_user_assignments aua
        JOIN admins a ON a.id = aua.admin_id
        WHERE aua.user_id = ?
          AND a.role IN ('account_manager', 'super_admin', 'seo_admin')
        ORDER BY a.name ASC
    ");
    $stmt->execute([(int) $userId]);
    return array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "name" => (string) $r["name"],
            "email" => (string) $r["email"],
            "role" => (string) $r["role"],
        ];
    }, $stmt->fetchAll());
}

if ($part === "team") {
    v1Reply(200, ["client" => $ref, "team" => clientTeam($pdo, $id)]);
}

if ($part === "note") {
    // Private to whoever wrote it, and only the roles that may write one
    // have one - admin-user-details.php $canManageNotes.
    $canWrite = in_array($p["role"], OWNER_AND_AM, true);
    v1Reply(200, [
        "client" => $ref,
        "can_write" => $canWrite,
        "untrusted_content" => [
            "note" => $canWrite ? (string) getClientNote($pdo, $id, $p["id"]) : "",
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| The overview
|--------------------------------------------------------------------------
*/

$today = date("Y-m-d");
$company = trim((string) ($client["company_name"] ?? ""));

$overview = [
    "client" => [
        "id" => (int) $client["id"],
        "name" => (string) $client["name"],
        "email" => (string) $client["email"],
        "company_name" => v1NullableString($client["company_name"]),
        "phone" => v1NullableString($client["phone"] ?? null),
        "whatsapp" => v1NullableString($client["whatsapp"] ?? null),
        "website" => v1NullableString($client["website"] ?? null),
        "approved" => (int) $client["approved"] === 1,
        "created_at" => (string) $client["created_at"],
        // Written by the client on their profile page.
        "untrusted_content" => ["description" => v1PlainText($client["description"] ?? "", 1000)],
    ],
    "team" => clientTeam($pdo, $id),
    // Sections this connection can't see, and the scope each one needs.
    "not_included" => [],
];

function overviewList(PDO $pdo, $sql, array $params)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // A deploy without one of the area tables still gets the rest.
        return [];
    }
}

if (hasScope($p, "forms:read")) {
    ensureSurveyColumns($pdo);
    $own = v1SeesAllForms($p) ? "" : " AND s.created_by_admin_id = " . (int) $p["id"];

    // Not yet answered: waiting on a reviewer, on the client, or returned.
    $overview["open_forms"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "status" => (string) $r["status"],
            "created_at" => (string) $r["created_at"],
        ];
    }, overviewList($pdo, "
        SELECT s.id, s.title, s.status, s.created_at
        FROM surveys s
        WHERE s.assigned_user_id = ? AND s.status IN ('pending_review', 'pending', 'rejected') $own
        ORDER BY s.id DESC
        LIMIT 10
    ", [$id]));

    // Responses follow the client, not the form's author (admin-responses.php).
    $overview["recent_responses"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "form_id" => (int) $r["survey_id"],
            "form_title" => (string) $r["survey_title"],
            "submitted_at" => (string) $r["submitted_at"],
        ];
    }, overviewList($pdo, "
        SELECT id, survey_id, survey_title, submitted_at
        FROM survey_responses
        WHERE user_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 5
    ", [$id]));
} else {
    $overview["not_included"][] = ["section" => "forms", "needs_scope" => "forms:read"];
}

if (hasScope($p, "content:read")) {
    ensureContentColumns($pdo);

    // Posts are tied to a client by company name (content.client).
    if ($company === "") {
        $overview["upcoming_content"] = [];
        $overview["recent_content"] = [];
    } else {
        $now = contentNow();
        $own = v1SeesAllContent($p) ? "" : " AND c.created_by = " . (int) $p["id"];
        $shape = function ($r) {
            return [
                "id" => (int) $r["id"],
                "title" => (string) $r["title"],
                "type_label" => v1NullableString($r["type_label"]),
                "status" => (string) $r["status"],
                "live_at" => v1DateTime($r["live_at"]),
            ];
        };

        // Drafts and scheduled posts dated from now on.
        $overview["upcoming_content"] = array_map($shape, overviewList($pdo, "
            SELECT c.id, c.title, c.type_label, " . contentStatusSql("c") . " AS status,
                   " . contentLiveAtSql("c") . " AS live_at
            FROM content c
            WHERE c.client = ? AND NOT " . contentIsLiveSql("c") . "
              AND " . contentLiveAtSql("c") . " >= ? $own
            ORDER BY live_at ASC, c.id ASC
            LIMIT 10
        ", [$now, $company, $now, $now]));

        $overview["recent_content"] = array_map($shape, overviewList($pdo, "
            SELECT c.id, c.title, c.type_label, 'published' AS status,
                   " . contentLiveAtSql("c") . " AS live_at
            FROM content c
            WHERE c.client = ? AND " . contentIsLiveSql("c") . " $own
            ORDER BY live_at DESC, c.id DESC
            LIMIT 5
        ", [$company, $now]));
    }
} else {
    $overview["not_included"][] = ["section" => "content", "needs_scope" => "content:read"];
}

if (hasScope($p, "calendar:read")) {
    ensureAppointmentTables($pdo);
    $overview["upcoming_meetings"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "date" => (string) $r["date"],
            "time" => (string) $r["time"],
            "status" => (string) $r["status"],
            "requested_by" => (string) $r["requested_by"],
            "admin_name" => v1NullableString($r["admin_name"]),
            "untrusted_content" => ["topic" => (string) ($r["topic"] ?? $r["title"] ?? "")],
        ];
    }, overviewList($pdo, "
        SELECT a.id, a.date, a.time, a.status, a.requested_by, a.topic, a.title,
               ad.name AS admin_name
        FROM appointments a
        LEFT JOIN admins ad ON ad.id = a.admin_id
        WHERE a.user_id = ? AND a.date >= ? AND a.status IN ('pending', 'approved')
        ORDER BY a.date ASC, a.time ASC
        LIMIT 10
    ", [$id, $today]));
} else {
    $overview["not_included"][] = ["section" => "meetings", "needs_scope" => "calendar:read"];
}

if (hasScope($p, "projects:read")) {
    ensureProjectTables($pdo);
    [$scopeSql, $scopeParams] = v1ProjectScopeSql($p);
    $overview["active_projects"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "status" => (string) $r["status"],
            "progress" => (int) $r["progress"],
            "start_date" => (string) $r["start_date"],
            "end_date" => (string) $r["end_date"],
        ];
    }, overviewList($pdo, "
        SELECT p.id, p.title, p.status, p.progress, p.start_date, p.end_date
        FROM projects p
        WHERE p.client_id = ? AND p.status <> 'completed'" . ($scopeSql !== "" ? " AND $scopeSql" : "") . "
        ORDER BY p.start_date DESC, p.id DESC
        LIMIT 10
    ", array_merge([$id], $scopeParams)));
} else {
    $overview["not_included"][] = ["section" => "projects", "needs_scope" => "projects:read"];
}

v1Reply(200, $overview);
