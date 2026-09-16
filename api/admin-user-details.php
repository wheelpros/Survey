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
| Authenticate the admin
|--------------------------------------------------------------------------
|
| Any admin may view a user. Clients Management itself is open to every admin,
| so narrowing here would load the page and then 401 the fetch.
|
| The role is read alongside the id because one thing on this page is narrower
| than the page: the internal note below is the account manager's and the
| owner's to write.
|
*/

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
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        respond(false, "Unauthorized.", [], 401);
    }
} catch (Throwable $e) {
    respond(false, "Authentication database error.", [], 500);
}

/* Who may change what is on this page: the internal note, and the client's own
   details. Every other admin reads both and no more.

   Approving, rejecting and deleting are not in here - those stay the owner's
   alone, enforced by api/admin-users.php and api/delete-client.php. */
$canManageNotes = in_array($admin["role"] ?? "", ["owner", "account_manager"], true);
$canEditDetails = $canManageNotes;

/*
|--------------------------------------------------------------------------
| Saving the internal note
|--------------------------------------------------------------------------
|
| The one write this endpoint takes. It comes before the read below because a
| POST has no business building the whole profile first.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $input["action"] ?? "";

    if (!in_array($action, ["save_notes", "save_details"], true)) {
        respond(false, "Unknown action.", [], 400);
    }

    if (!$canEditDetails) {
        respond(false, "Only an account manager or the owner can edit a client.", [], 403);
    }

    $targetId = (int) ($input["id"] ?? 0);

    if (!$targetId) {
        respond(false, "A numeric user id is required.", [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | The client's own details
    |--------------------------------------------------------------------------
    |
    | The same five fields the client edits on profile.html, editable here so an
    | account manager can correct a number or fill in a company the client left
    | blank without asking them to log in and do it.
    |
    | Deliberately not here: name and email are the account's identity, and
    | approved is a decision rather than a detail - both have their own
    | endpoints, with their own rules about who may change them.
    |
    | Each value gets the same treatment api/update-profile.php gives it, so a
    | number saved here and a number saved there end up in the same shape.
    |
    */

    if ($action === "save_details") {

        ensureUserProfileColumns($pdo);

        $company = trim((string) ($input["company_name"] ?? ""));
        $phone = trim((string) ($input["phone"] ?? ""));
        $about = trim((string) ($input["description"] ?? ""));
        $websiteInput = trim((string) ($input["website"] ?? ""));
        $whatsappInput = trim((string) ($input["whatsapp"] ?? ""));

        if (mb_strlen($company) > 150) {
            respond(false, "The company name has to be 150 characters or fewer.", [], 400);
        }

        if ($phone !== "" && !preg_match("/^[0-9+\-\s().]{6,25}$/", $phone)) {
            respond(false, "That does not look like a phone number.", [], 400);
        }

        // A bare domain is how it is usually typed; store something linkable.
        $website = normaliseWebUrl($websiteInput);

        if ($websiteInput !== "" && $website === "") {
            respond(false, "That does not look like a web address.", [], 400);
        }

        // The country code is the part worth insisting on - see db.php.
        $whatsapp = normaliseWhatsApp($whatsappInput);

        if ($whatsappInput !== "" && $whatsapp === "") {
            respond(false, "Start the WhatsApp number with its country code, like +44 7911 123456.", [], 400);
        }

        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $check->execute([$targetId]);

            if (!$check->fetch()) {
                respond(false, "User not found.", [], 404);
            }

            $save = $pdo->prepare("
                UPDATE users
                SET company_name = ?, website = ?, description = ?, phone = ?, whatsapp = ?
                WHERE id = ?
            ");

            $save->execute([
                $company === "" ? null : $company,
                $website === "" ? null : $website,
                $about === "" ? null : $about,
                $phone === "" ? null : $phone,
                $whatsapp === "" ? null : $whatsapp,
                $targetId
            ]);

        } catch (Throwable $e) {
            respond(false, "Could not save the details.", [], 500);
        }

        /* The saved values go back, not the submitted ones: the page repaints
           from these, so what it shows is what is stored rather than what was
           typed. */
        respond(true, "Details saved.", [
            "details" => [
                "company_name" => $company,
                "website" => $website,
                "description" => $about,
                "phone" => $phone,
                "whatsapp" => $whatsapp
            ]
        ]);
    }

    $note = trim((string) ($input["notes"] ?? ""));

    // TEXT holds far more than anyone types into a note field, but a request
    // is not a promise about its own size.
    if (mb_strlen($note) > 5000) {
        respond(false, "A note has to be 5000 characters or fewer.", [], 400);
    }

    ensureClientNoteColumns($pdo);

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $check->execute([$targetId]);

        if (!$check->fetch()) {
            respond(false, "User not found.", [], 404);
        }

        $save = $pdo->prepare("UPDATE users SET admin_notes = ? WHERE id = ?");
        $save->execute([$note === "" ? null : $note, $targetId]);

    } catch (Throwable $e) {
        respond(false, "Could not save the note.", [], 500);
    }

    respond(true, $note === "" ? "Note cleared." : "Note saved.", [
        "notes" => $note
    ]);
}

/*
|--------------------------------------------------------------------------
| The user
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id"]) || !ctype_digit((string) $_GET["id"])) {
    respond(false, "A numeric user id is required.", [], 400);
}

$userId = (int) $_GET["id"];

// company_name, website, description and phone are added lazily by db.php.
ensureUserProfileColumns($pdo);

// And so is admin_notes, which is not one of them - see db.php.
ensureClientNoteColumns($pdo);

try {
    // Explicit columns: never expose password or session_token.
    $stmt = $pdo->prepare("
        SELECT
            id, name, email, phone, whatsapp,
            company_name, website, description,
            admin_notes,
            profile_image, approved, created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    respond(false, "Could not load the user.", [], 500);
}

if (!$user) {
    respond(false, "User not found.", [], 404);
}

/* The address to open, worked out here rather than on the page: it is the same
   rule wa.me needs everywhere, and an empty string is the page's cue that there
   is no icon to draw. */
$user["whatsapp_link"] = whatsAppLink($user["whatsapp"] ?? "");

/*
|--------------------------------------------------------------------------
| Activity
|--------------------------------------------------------------------------
|
| Each list is the query the matching admin page already runs, narrowed to
| this user. They are wrapped individually so a deploy missing one table
| still renders the profile instead of failing the whole request.
|
*/

function fetchList(PDO $pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$surveys = fetchList($pdo, "
    SELECT id, title, status, created_at
    FROM surveys
    WHERE assigned_user_id = ?
    ORDER BY created_at DESC
", [$userId]);

$responses = fetchList($pdo, "
    SELECT id, survey_id, survey_title, submitted_at
    FROM survey_responses
    WHERE user_id = ?
    ORDER BY id DESC
", [$userId]);

$appointments = fetchList($pdo, "
    SELECT a.id, a.title, a.topic, a.notes, a.date, a.time, a.status,
           a.requested_by, a.client, ad.name AS admin_name
    FROM appointments a
    LEFT JOIN admins ad ON ad.id = a.admin_id
    WHERE a.user_id = ?
    ORDER BY a.date DESC, a.time DESC
", [$userId]);

$files = fetchList($pdo, "
    SELECT sf.id, sf.original_name, sf.file_size, sf.uploaded_at
    FROM user_source_files usf
    JOIN source_files sf ON sf.id = usf.file_id
    WHERE usf.user_id = ?
    ORDER BY usf.assigned_at DESC
", [$userId]);

/*
| Content this user can see: public posts plus anything targeted at their
| client. Same rule as api/user-content.php - this is the answer to "why does
| this user see that post?".
*/
$company = trim($user["company_name"] ?? "");

$content = fetchList($pdo, "
    SELECT id, title, platform, type_label, client, status, created_at
    FROM content
    WHERE status = 'published'
      AND (client IS NULL OR client = '' OR (? <> '' AND client = ?))
    ORDER BY created_at DESC
", [$company, $company]);

/*
| The SEO admin managing this user, if any.
*/
$seoAdmin = null;

$managing = fetchList($pdo, "
    SELECT a.id, a.name, a.email
    FROM admin_user_assignments aua
    JOIN admins a ON a.id = aua.admin_id
    WHERE aua.user_id = ?
    LIMIT 1
", [$userId]);

if ($managing) {
    $seoAdmin = $managing[0];
}

respond(true, "User loaded.", [
    "user"         => $user,

    /* Presentation only - the POST above checks the role itself. The page uses
       these to decide whether the note and the details are fields or text. */
    "canManageNotes" => $canManageNotes,
    "canEditDetails" => $canEditDetails,

    "seoAdmin"     => $seoAdmin,
    "surveys"      => $surveys,
    "responses"    => $responses,
    "appointments" => $appointments,
    "files"        => $files,
    "content"      => $content,
]);
