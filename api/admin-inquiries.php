<?php

require_once "db.php";

header("Content-Type: application/json");

ensureInquiryTables($pdo);

$headers = getallheaders();
$token = str_replace("Bearer ", "", $headers["Authorization"] ?? "");

$stmt = $pdo->prepare("SELECT id, role, inquiries_access FROM admins WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$currentAdmin = $stmt->fetch();

if (!$currentAdmin) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$hasAccess = $currentAdmin["role"] === "owner"
    || ($currentAdmin["role"] === "account_manager" && (int)$currentAdmin["inquiries_access"] === 1);

if (!$hasAccess) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$isOwner = $currentAdmin["role"] === "owner";
$method = $_SERVER["REQUEST_METHOD"];

/*
| The readable half of a public link. A title becomes the `name` in
|
|     inquiry.html?name=free-30-minute-business-growth-consultation&token=...
|
| and the endpoint that serves that link checks the two against each other, so
| the slug has to be unique. A title of nothing but punctuation would slugify to
| an empty string, hence the "inquiry" fallback.
*/
function slugifyInquiryTitle($pdo, $title, $excludeId = 0)
{
    $base = strtolower(trim($title));
    $base = preg_replace("/[^a-z0-9]+/", "-", $base);
    $base = trim($base, "-");
    $base = substr($base, 0, 120);
    $base = trim($base, "-");

    if ($base === "") {
        $base = "inquiry";
    }

    $slug = $base;
    $suffix = 1;

    // -2, -3, ... until nothing else holds it. Bounded by the number of
    // inquiries sharing a title, which is a handful at worst.
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM inquiries WHERE slug = ? AND id <> ? LIMIT 1");
        $stmt->execute([$slug, (int)$excludeId]);

        if (!$stmt->fetch()) {
            return $slug;
        }

        $suffix++;
        $slug = $base . "-" . $suffix;
    }
}

// Every inquiry needs a slug, and the rows written before links carried a name
// have none. Filled in the first time such a row is read or saved.
function backfillSlug($pdo, $inquiryId, $title, $currentSlug)
{
    if (!empty($currentSlug)) {
        return $currentSlug;
    }

    $slug = slugifyInquiryTitle($pdo, $title, $inquiryId);
    $pdo->prepare("UPDATE inquiries SET slug = ? WHERE id = ?")->execute([$slug, $inquiryId]);

    return $slug;
}

/* Answers point at the inquiry's field rows, and a save rewrites those rows.
   Once one exists the questions are frozen - this is that test. */
function inquiryHasAnswers($pdo, $inquiryId)
{
    $stmt = $pdo->prepare("SELECT 1 FROM inquiry_responses WHERE inquiry_id = ? LIMIT 1");
    $stmt->execute([$inquiryId]);
    return (bool)$stmt->fetch();
}

// Anything that is not a live account_manager is stored as "nobody", rather
// than trusting an id the form happened to send.
function resolveManagerId($pdo, $raw)
{
    $id = (int)$raw;

    if (!$id) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND role = 'account_manager' LIMIT 1");
    $stmt->execute([$id]);

    return $stmt->fetch() ? $id : null;
}

/*
| The question types. 'choice' takes any number of answers, 'select' exactly
| one; both need a list to choose from, which is what makes them different from
| the two free-text types.
|
| Stored as VARCHAR and checked here rather than as an ENUM, the same as
| content.content_type and projects.project_type - adding a type never needs an
| ALTER the database user may not have.
*/
const INQUIRY_FIELD_TYPES = ["input", "textarea", "choice", "select"];

function normaliseFieldType($raw)
{
    return in_array($raw, INQUIRY_FIELD_TYPES, true) ? $raw : "input";
}

/* Options arrive as the admin typed them: one per line. Blank lines go, so do
   duplicates - a list offering the same answer twice is a mistake every time -
   and the whole thing is capped so a paste cannot write a novel into the row. */
function normaliseOptions($raw)
{
    $lines = preg_split("/\r\n|\r|\n/", (string)$raw);
    $clean = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === "" || in_array($line, $clean, true)) {
            continue;
        }

        $clean[] = mb_substr($line, 0, 200);

        if (count($clean) >= 50) {
            break;
        }
    }

    return $clean;
}

/* Writing the questions, for both create and edit. A choice question with no
   options left after cleaning is stored as free text rather than as a list
   with nothing in it - the form refuses that case first, this is the backstop. */
function writeFields($pdo, $inquiryId, $fields)
{
    $stmt = $pdo->prepare("
        INSERT INTO inquiry_fields (inquiry_id, field_label, field_type, required, options, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $order = 0;

    foreach ($fields as $field) {
        $label = trim($field["label"] ?? "");
        if (!$label) continue;

        $type = normaliseFieldType($field["type"] ?? "input");
        $options = ($type === "choice" || $type === "select")
            ? normaliseOptions($field["options"] ?? "")
            : [];

        if (($type === "choice" || $type === "select") && !count($options)) {
            $type = "input";
        }

        $order++;

        $stmt->execute([
            $inquiryId,
            $label,
            $type,
            !empty($field["required"]) ? 1 : 0,
            count($options) ? implode("\n", $options) : null,
            $order
        ]);
    }
}

function normaliseStatus($raw)
{
    return ($raw === "inactive") ? "inactive" : "active";
}

if ($method === "GET") {

    // The account manager picker on inquiry-form.html. It lives here rather
    // than on api/inquiries-access.php because that endpoint is owner-only and
    // an access-granted account manager can create inquiries too.
    if (isset($_GET["managers"])) {

        $stmt = $pdo->query("
            SELECT id, name, email
            FROM admins
            WHERE role = 'account_manager'
            ORDER BY name ASC
        ");

        echo json_encode([
            "success" => true,
            "managers" => $stmt->fetchAll()
        ]);
        exit;
    }

    $singleId = (int)($_GET["id"] ?? 0);

    if ($singleId) {

        $stmt = $pdo->prepare("
            SELECT
                inquiries.id, inquiries.title, inquiries.intro_text, inquiries.slug,
                inquiries.status, inquiries.account_manager_admin_id, inquiries.created_at,
                manager.name AS account_manager_name,
                manager.email AS account_manager_email
            FROM inquiries
            LEFT JOIN admins manager ON manager.id = inquiries.account_manager_admin_id
            WHERE inquiries.id = ?
            LIMIT 1
        ");
        $stmt->execute([$singleId]);
        $inquiry = $stmt->fetch();

        if (!$inquiry) {
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        $inquiry["slug"] = backfillSlug($pdo, $inquiry["id"], $inquiry["title"], $inquiry["slug"]);

        $fieldsStmt = $pdo->prepare("
            SELECT id, field_label, field_type, required, options, sort_order
            FROM inquiry_fields
            WHERE inquiry_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $fieldsStmt->execute([$singleId]);

        echo json_encode([
            "success" => true,
            "inquiry" => $inquiry,
            "fields" => $fieldsStmt->fetchAll(),
            "hasAnswers" => inquiryHasAnswers($pdo, $singleId)
        ]);
        exit;
    }

    // Each inquiry is a template; "leads" counts the invites that were actually
    // answered, "invites" counts every link ever generated for it.
    $stmt = $pdo->query("
        SELECT
            inquiries.id, inquiries.title, inquiries.slug, inquiries.status,
            inquiries.account_manager_admin_id, inquiries.created_at,
            manager.name AS account_manager_name,
            (SELECT COUNT(*) FROM inquiry_responses WHERE inquiry_responses.inquiry_id = inquiries.id) AS lead_count
        FROM inquiries
        LEFT JOIN admins manager ON manager.id = inquiries.account_manager_admin_id
        ORDER BY inquiries.created_at DESC, inquiries.id DESC
    ");
    $inquiries = $stmt->fetchAll();

    foreach ($inquiries as &$row) {
        $row["slug"] = backfillSlug($pdo, $row["id"], $row["title"], $row["slug"]);
    }
    unset($row);

    /* The three stat cards. Counted in SQL over everything, deliberately
       ignoring the page the table happens to be showing:

         total   inquiries created
         active  inquiries whose links are open for answering
         people  submissions, across all of them
    */
    $stats = [
        "total" => (int)$pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(),
        "active" => (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE status <> 'inactive'")->fetchColumn(),
        "people" => (int)$pdo->query("SELECT COUNT(*) FROM inquiry_responses")->fetchColumn(),
    ];

    echo json_encode([
        "success" => true,
        "inquiries" => $inquiries,
        "stats" => $stats
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    // Creating an inquiry is open to the owner and any access-granted account
    // manager - editing and deleting stay owner-only.
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];
    $status = normaliseStatus($input["status"] ?? "active");
    $managerId = resolveManagerId($pdo, $input["accountManagerId"] ?? 0);

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A title and at least one field are required"]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        $slug = slugifyInquiryTitle($pdo, $title);

        $stmt = $pdo->prepare("
            INSERT INTO inquiries (title, intro_text, slug, status, account_manager_admin_id, created_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $introText, $slug, $status, $managerId, $currentAdmin["id"]]);
        $inquiryId = $pdo->lastInsertId();

        writeFields($pdo, $inquiryId, $fields);

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Inquiry created successfully",
            "id" => $inquiryId,
            "name" => $slug
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => $e->getMessage() ?: "Failed to save inquiry"]);
        exit;
    }
}

if ($method === "PUT") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can edit inquiries"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $inquiryId = (int)($input["id"] ?? 0);
    $title = trim($input["title"] ?? "");
    $introText = trim($input["introText"] ?? "");
    $fields = $input["fields"] ?? [];
    $status = normaliseStatus($input["status"] ?? "active");
    $managerId = resolveManagerId($pdo, $input["accountManagerId"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    if (!$title || !count($fields)) {
        echo json_encode(["success" => false, "message" => "A title and at least one field are required"]);
        exit;
    }

    $checkStmt = $pdo->prepare("SELECT id, title, slug FROM inquiries WHERE id = ? LIMIT 1");
    $checkStmt->execute([$inquiryId]);
    $existing = $checkStmt->fetch();

    if (!$existing) {
        echo json_encode(["success" => false, "message" => "Inquiry not found"]);
        exit;
    }

    /* Once any link has been answered, rewriting the fields would delete the
       rows those answers point at. Status and the account manager stay
       editable even then, so a live inquiry can still be closed or handed
       over - neither touches anything historical. */
    if (inquiryHasAnswers($pdo, $inquiryId)) {

        $stmt = $pdo->prepare("UPDATE inquiries SET status = ?, account_manager_admin_id = ? WHERE id = ?");
        $stmt->execute([$status, $managerId, $inquiryId]);

        echo json_encode([
            "success" => true,
            "locked" => true,
            "name" => $existing["slug"],
            "message" => "Status and account manager saved. The questions are locked - this inquiry already has answers."
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        // A title change moves the name in every link handed out from now on.
        $slug = ($existing["title"] === $title && !empty($existing["slug"]))
            ? $existing["slug"]
            : slugifyInquiryTitle($pdo, $title, $inquiryId);

        $updateStmt = $pdo->prepare("
            UPDATE inquiries
            SET title = ?, intro_text = ?, slug = ?, status = ?, account_manager_admin_id = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$title, $introText, $slug, $status, $managerId, $inquiryId]);

        $deleteFieldsStmt = $pdo->prepare("DELETE FROM inquiry_fields WHERE inquiry_id = ?");
        $deleteFieldsStmt->execute([$inquiryId]);

        writeFields($pdo, $inquiryId, $fields);

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Inquiry updated successfully",
            "name" => $slug
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to save inquiry"]);
        exit;
    }
}

if ($method === "DELETE") {

    if (!$isOwner) {
        echo json_encode(["success" => false, "message" => "Only the owner can delete inquiries"]);
        exit;
    }

    $inquiryId = (int)($_GET["id"] ?? 0);

    if (!$inquiryId) {
        echo json_encode(["success" => false, "message" => "Inquiry id is required"]);
        exit;
    }

    // No foreign keys on these tables, so the children go by hand, deepest
    // first - the same thing api/projects.php does for its tasks and members.
    $pdo->beginTransaction();

    try {
        $pdo->prepare("
            DELETE FROM inquiry_response_answers
            WHERE response_id IN (SELECT id FROM inquiry_responses WHERE inquiry_id = ?)
        ")->execute([$inquiryId]);

        $pdo->prepare("DELETE FROM inquiry_responses WHERE inquiry_id = ?")->execute([$inquiryId]);
        $pdo->prepare("DELETE FROM inquiry_invites WHERE inquiry_id = ?")->execute([$inquiryId]);
        $pdo->prepare("DELETE FROM inquiry_fields WHERE inquiry_id = ?")->execute([$inquiryId]);

        $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id = ?");
        $stmt->execute([$inquiryId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Inquiry not found"]);
            exit;
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Inquiry deleted"]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to delete inquiry"]);
        exit;
    }
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
