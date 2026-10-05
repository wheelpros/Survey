<?php

/*
|--------------------------------------------------------------------------
| Calendar: meeting requests between admins and clients
|--------------------------------------------------------------------------
|
| Backs dashboard.html and admin-calendar.html. One row in
| `appointments` is one request, and `requested_by` says which way it points:
|
|   'admin'  an admin asked a client for time. The user accepts or declines.
|   'user'   a client asked the admin for time. An admin accepts or declines.
|
| Both pages read the same rows; which panel a row lands in is decided by that
| column plus `status`. sql/appointment_requests.sql is the schema.
|
*/

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "db.php";
require_once "meeting-writes.php";

function reply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Who is calling
|--------------------------------------------------------------------------
|
| One token space for two audiences: a users row or an admins row. Whichever
| matches decides which half of the actions below are reachable.
|
*/

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? "";

if (!$authHeader && isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authHeader = $_SERVER["HTTP_AUTHORIZATION"];
}

$token = trim(str_replace("Bearer", "", $authHeader));

if (!$token) {
    reply(false, "No token provided");
}

ensureUserProfileColumns($pdo);
ensureAppointmentTables($pdo);
ensureNotificationsTable($pdo);

$stmt = $pdo->prepare("
    SELECT id, name, email, company_name
    FROM users
    WHERE session_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$admin = null;

if (!$user) {
    $stmt = $pdo->prepare("
        SELECT id, name, email, role
        FROM admins
        WHERE session_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        reply(false, "Unauthorized token");
    }

    /*
    |--------------------------------------------------------------------------
    | Which admin roles reach the calendar
    |--------------------------------------------------------------------------
    |
    | The calendar is about client meetings, so it belongs to the roles that
    | deal with clients: the owner, super admins and account managers. A plain
    | `seo_admin` works on content and never books time with a client, so the
    | whole file is closed to that role - hiding the page would not be enough
    | on its own, since the endpoint is reachable directly.
    |
    */

    $calendarRoles = ["owner", "super_admin", "account_manager"];

    if (!in_array($admin["role"] ?? "", $calendarRoles, true)) {
        reply(false, "You do not have access to the calendar.");
    }
}

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$input = json_decode(file_get_contents("php://input"), true) ?? [];

function field($input, $name, $default = "")
{
    return trim((string) ($input[$name] ?? $_POST[$name] ?? $default));
}

/*
|--------------------------------------------------------------------------
| Which clients an admin may act on
|--------------------------------------------------------------------------
|
| The same scoping Clients Management applies: the owner sees every client,
| everyone else only the ones assigned to them. Returned as full rows because
| every caller here wants the company name alongside the id.
|
*/

function scopedClients(PDO $pdo, array $admin)
{
    // Shared with api/v1 - see meeting-writes.php.
    return meetingScopedClients($pdo, $admin);
}

/*
|--------------------------------------------------------------------------
| USER ACTIONS
|--------------------------------------------------------------------------
*/

/* Accept or decline a request an admin sent this client. */
if ($action === "respond_appointment" && $user) {

    $status = field($input, "status");

    // answerAdminMeetingRequest() is shared with api/v1 (meeting-writes.php).
    try {
        answerAdminMeetingRequest($pdo, $user, (int) field($input, "id", "0"), $status);
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage());
    }

    reply(true, $status === "approved" ? "Meeting confirmed" : "Meeting declined");
}

/* The "Send a new request" form on dashboard.html. */
if ($action === "create_user_request" && $user) {

    // requestMeetingFromAdmins() is shared with api/v1 (meeting-writes.php):
    // the row, notifications and emails are the same whoever sends it.
    try {
        requestMeetingFromAdmins($pdo, $user, field($input, "date"), field($input, "time"), field($input, "topic"), field($input, "notes"));
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage());
    }

    reply(true, "Request sent to the admin team.");
}

/* Everything dashboard.html paints, in one round trip. */
if ($action === "get_user_calendar" && $user) {

    // A declined admin request drops off the client's calendar, but their own
    // declined request stays: they asked, they are owed the answer.
    $stmt = $pdo->prepare("
        SELECT a.*, ad.name AS admin_name
        FROM appointments a
        LEFT JOIN admins ad ON ad.id = a.admin_id
        WHERE a.user_id = ?
          AND NOT (a.status = 'rejected' AND a.requested_by = 'admin')
        ORDER BY a.date DESC, a.time DESC
    ");
    $stmt->execute([$user["id"]]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    reply(true, "", [
        "appointments" => $appointments
    ]);
}

/*
|--------------------------------------------------------------------------
| ADMIN ACTIONS
|--------------------------------------------------------------------------
*/

/* The Client dropdown, built the same way admin-content-form.html builds it:
   the distinct company names on the clients this admin can reach. */
if ($action === "get_clients" && $admin) {

    $clients = [];

    foreach (scopedClients($pdo, $admin) as $row) {
        $company = trim((string) ($row["company_name"] ?? ""));

        if ($company !== "" && !in_array($company, $clients, true)) {
            $clients[] = $company;
        }
    }

    sort($clients);

    reply(true, "", ["clients" => $clients]);
}

/* The "Send a new request" form on admin-calendar.html.

   The admin picks a company, not a person - the same choice content posts
   offer - so this fans out to every client account carrying that name. One
   row each, because each client answers for their own diary. */
if ($action === "create_admin_request" && $admin) {

    $client = field($input, "client");

    if ($client === "") {
        reply(false, "Pick the client this request is for.");
    }

    // requestMeetingFromClients() is shared with api/v1 (meeting-writes.php):
    // the rows, notifications and emails are the same whoever sends it.
    try {
        $ids = requestMeetingFromClients(
            $pdo,
            $admin,
            meetingTargetsForCompany($pdo, $admin, $client),
            $client,
            field($input, "date"),
            field($input, "time"),
            field($input, "topic"),
            field($input, "notes")
        );
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage());
    }

    $sent = count($ids);
    reply(true, "Request sent to {$sent}" . ($sent === 1 ? " client." : " clients."));
}

/* Accept or decline a request a client sent the admin team. */
if ($action === "respond_request" && $admin) {

    $status = field($input, "status");

    try {
        answerClientMeetingRequest($pdo, $admin, (int) field($input, "id", "0"), $status);
    } catch (PortalWriteError $e) {
        reply(false, $e->getMessage());
    }

    reply(true, $status === "approved" ? "Meeting confirmed" : "Request declined");
}

/* Everything admin-calendar.html paints, across the admin's whole scope. */
if ($action === "get_admin_calendar" && $admin) {

    $clients = scopedClients($pdo, $admin);
    $ids = array_map("intval", array_column($clients, "id"));

    if (!$ids) {
        reply(true, "", ["appointments" => [], "clients" => []]);
    }

    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    $stmt = $pdo->prepare("
        SELECT a.*, u.name AS user_name, u.company_name, ad.name AS admin_name
        FROM appointments a
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN admins ad ON ad.id = a.admin_id
        WHERE a.user_id IN ($placeholders)
        ORDER BY a.date DESC, a.time DESC
    ");
    $stmt->execute($ids);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    reply(true, "", [
        "appointments" => $appointments,
        "clients" => $clients,
        "admin_id" => (int) $admin["id"]
    ]);
}

/*
|--------------------------------------------------------------------------
| Older admin actions, kept for callers that have not moved over
|--------------------------------------------------------------------------
*/

if ($action === "create_appointment" && $admin) {

    $targetUserId = (int) field($input, "user_id", "0");
    $title = field($input, "title", "Meeting");
    $date = field($input, "date");
    $time = field($input, "time");

    if (!$targetUserId || !$date || !$time) {
        reply(false, "Missing required appointment fields");
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, requested_by, admin_id)
        VALUES (?, ?, ?, ?, 'pending', ?, 'admin', ?)
    ");
    $stmt->execute([$targetUserId, $title, $date, $time, $title, $admin["id"]]);

    reply(true, "Appointment request sent to user");
}

if ($action === "get_admin_calendar_data" && $admin) {

    $targetUserId = (int) ($_GET["user_id"] ?? $input["user_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = ?");
    $stmt->execute([$targetUserId]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    reply(true, "", ["appointments" => $appointments]);
}

reply(false, "Invalid action or permission denied");
