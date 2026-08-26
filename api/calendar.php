<?php

/*
|--------------------------------------------------------------------------
| Calendar: meeting requests between admins and clients
|--------------------------------------------------------------------------
|
| Backs user-appointments.html and admin-calendar.html. One row in
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
require_once "notify.php";

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
    if (($admin["role"] ?? "") === "owner") {
        $stmt = $pdo->query("
            SELECT id, name, email, company_name
            FROM users
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.company_name
        FROM users u
        INNER JOIN admin_user_assignments a ON a.user_id = u.id
        WHERE a.admin_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$admin["id"]]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| USER ACTIONS
|--------------------------------------------------------------------------
*/

/* Accept or decline a request an admin sent this client. */
if ($action === "respond_appointment" && $user) {

    $appointmentId = (int) field($input, "id", "0");
    $status = field($input, "status");

    if (!in_array($status, ["approved", "rejected"], true)) {
        reply(false, "Invalid status");
    }

    // requested_by is checked so a client cannot answer their own request -
    // that one is the admin team's to decide.
    // Read before writing, so the admin who asked can be told the answer.
    $lookup = $pdo->prepare("
        SELECT admin_id, topic
        FROM appointments
        WHERE id = ? AND user_id = ? AND requested_by = 'admin'
        LIMIT 1
    ");
    $lookup->execute([$appointmentId, $user["id"]]);
    $request = $lookup->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = ?
        WHERE id = ? AND user_id = ? AND requested_by = 'admin'
    ");
    $stmt->execute([$status, $appointmentId, $user["id"]]);

    if ($request && (int) $request["admin_id"] > 0) {
        notify(
            $pdo,
            "admin",
            (int) $request["admin_id"],
            NOTIFY_APPOINTMENT_ANSWERED,
            $user["name"] . ($status === "approved" ? " confirmed your meeting" : " declined your meeting"),
            (string) ($request["topic"] ?? "Meeting"),
            "admin-calendar.html",
            "user",
            (int) $user["id"]
        );
    }

    reply(true, $status === "approved" ? "Meeting confirmed" : "Meeting declined");
}

/* The "Send a new request" form on user-appointments.html. */
if ($action === "create_user_request" && $user) {

    $date = field($input, "date");
    $time = field($input, "time");
    $topic = mb_substr(field($input, "topic"), 0, 200);
    $notes = field($input, "notes");

    if (!$date || !$time || $topic === "") {
        reply(false, "Pick a date and time, and say what the meeting is about.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, notes, requested_by, client)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, 'user', ?)
    ");
    $stmt->execute([
        $user["id"],
        $topic,
        $date,
        $time,
        $topic,
        $notes !== "" ? $notes : null,
        $user["company_name"] ?? null
    ]);

    notifyAdminsForUser(
        $pdo,
        (int) $user["id"],
        NOTIFY_APPOINTMENT_REQUEST,
        "Meeting request from " . $user["name"],
        $topic . " - " . $date . " at " . $time,
        "admin-calendar.html"
    );

    reply(true, "Request sent to the admin team.");
}

/* Everything user-appointments.html paints, in one round trip. */
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
    $date = field($input, "date");
    $time = field($input, "time");
    $topic = mb_substr(field($input, "topic"), 0, 200);
    $notes = field($input, "notes");

    if ($client === "") {
        reply(false, "Pick the client this request is for.");
    }

    if (!$date || !$time || $topic === "") {
        reply(false, "Pick a date and time, and say what the meeting is about.");
    }

    $targets = [];

    foreach (scopedClients($pdo, $admin) as $row) {
        if (trim((string) ($row["company_name"] ?? "")) === $client) {
            $targets[] = $row;
        }
    }

    if (!$targets) {
        reply(false, "No client accounts carry that company name.");
    }

    $insert = $pdo->prepare("
        INSERT INTO appointments
            (user_id, title, date, time, status, topic, notes, requested_by, admin_id, client)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, 'admin', ?, ?)
    ");

    $sent = 0;

    foreach ($targets as $target) {

        $insert->execute([
            $target["id"],
            $topic,
            $date,
            $time,
            $topic,
            $notes !== "" ? $notes : null,
            $admin["id"],
            $client
        ]);

        // One notification per account, matching the one row each of them got.
        notify(
            $pdo,
            "user",
            (int) $target["id"],
            NOTIFY_APPOINTMENT_REQUEST,
            "Meeting request from " . $admin["name"],
            $topic . " - " . $date . " at " . $time,
            "user-appointments.html",
            "admin",
            (int) $admin["id"]
        );

        $sent++;
    }

    reply(true, "Request sent to {$sent}" . ($sent === 1 ? " client." : " clients."));
}

/* Accept or decline a request a client sent the admin team. */
if ($action === "respond_request" && $admin) {

    $appointmentId = (int) field($input, "id", "0");
    $status = field($input, "status");

    if (!in_array($status, ["approved", "rejected"], true)) {
        reply(false, "Invalid status");
    }

    $allowed = array_map("intval", array_column(scopedClients($pdo, $admin), "id"));

    $stmt = $pdo->prepare("
        SELECT user_id, topic
        FROM appointments
        WHERE id = ? AND requested_by = 'user'
        LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !in_array((int) $row["user_id"], $allowed, true)) {
        reply(false, "That request is not yours to answer.");
    }

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = ?, admin_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $admin["id"], $appointmentId]);

    notify(
        $pdo,
        "user",
        (int) $row["user_id"],
        NOTIFY_APPOINTMENT_ANSWERED,
        $status === "approved" ? "Your meeting was confirmed" : "Your meeting request was declined",
        (string) ($row["topic"] ?? "Meeting"),
        "user-appointments.html",
        "admin",
        (int) $admin["id"]
    );

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
