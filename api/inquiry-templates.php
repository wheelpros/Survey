<?php

/*
|--------------------------------------------------------------------------
| Inquiries: the template side - slugs, questions, creating one
|--------------------------------------------------------------------------
|
| Moved out of admin-inquiries.php so the AI-facing api/v1 can create an
| inquiry draft through the same code as inquiry-form.html: the same slug
| rule, the same question types and the same storage.
*/

require_once __DIR__ . "/db.php";

/*
| The readable half of a public link. A title becomes the `name` in
|
|     inquiry.html?name=free-30-minute-business-growth-consultation&token=...
|
| and the endpoint that serves that link checks the two against each other, so
| the slug has to be unique. A title with no Latin letters or digits at all -
| an Arabic title, or nothing but punctuation - would slugify to an empty
| string. It used to fall back to a bare "inquiry", then "inquiry-2", "-3"...,
| which says nothing about which form a link opens and depends on the order
| forms were made in; such a title now gets "inquiry-" plus a short random
| tail instead. Links stay ASCII either way, which the AI-facing
| api/inquiry-lookup.php and the MCP server require.
*/
function slugifyInquiryTitle($pdo, $title, $excludeId = 0)
{
    $base = strtolower(trim($title));
    $base = preg_replace("/[^a-z0-9]+/", "-", $base);
    $base = trim($base, "-");
    $base = substr($base, 0, 120);
    $base = trim($base, "-");

    if ($base === "") {
        $base = "inquiry-" . substr(bin2hex(random_bytes(3)), 0, 6);
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
| The question types an inquiry can be built from: one line of text, or a
| paragraph of it.
|
| 'choice' and 'select' are gone from this list on purpose. Questions of those
| types written before still render on the public page and still read back on
| the details page, because nothing here deletes them - but the form builder no
| longer offers them, and normaliseFieldType() below turns one into 'input' if
| an old inquiry is saved again.
|
| Stored as VARCHAR and checked here rather than as an ENUM, the same as
| content.content_type and projects.project_type - adding a type never needs an
| ALTER the database user may not have.
*/
const INQUIRY_FIELD_TYPES = ["input", "textarea"];

/* How long an answer of each type may be. Fixed by the type and not settable
   per question: "short answer" and "paragraph" are the promise, and a number
   attached to each is what makes them mean something. api/public-inquiry.php
   enforces the same two figures on the way in. */
const INQUIRY_FIELD_MAX_CHARS = [
    "input"    => 120,
    "textarea" => 800,
];

function normaliseFieldType($raw)
{
    return in_array($raw, INQUIRY_FIELD_TYPES, true) ? $raw : "input";
}


/* Writing the questions, for both create and edit. Only the two free-text
   types can be written, so `options` is always NULL on a row written here -
   the column carries nothing but what the legacy list questions put in it. */
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

        /* Only the two free-text types can be written now, so nothing reaching
           here has options to store. */
        $type = normaliseFieldType($field["type"] ?? "input");

        $order++;

        $stmt->execute([
            $inquiryId,
            $label,
            $type,
            !empty($field["required"]) ? 1 : 0,
            null,
            $order
        ]);
    }
}

function normaliseStatus($raw)
{
    return ($raw === "inactive") ? "inactive" : "active";
}

/* The office's own handle for the inquiry. Free text, trimmed and capped to
   the column; empty is stored as NULL so the list can tell "not set" from a
   reference that is a single space. */
function normaliseReference($raw)
{
    $value = trim((string) $raw);
    return $value === "" ? null : mb_substr($value, 0, 100);
}

/**
 * Creates an inquiry and its questions. Returns ["id" => int, "slug" => string].
 * Throws PortalWriteError for a missing title or questions.
 */
function createInquiry(PDO $pdo, array $actor, $title, $introText, array $fields, $status, $managerId, $reference)
{
    $title = trim((string) $title);
    $introText = trim((string) $introText);

    if (!$title || !count($fields)) {
        throw new PortalWriteError("A title and at least one field are required");
    }

    $pdo->beginTransaction();

    try {
        $slug = slugifyInquiryTitle($pdo, $title);

        $stmt = $pdo->prepare("
            INSERT INTO inquiries
                (title, intro_text, slug, status, account_manager_admin_id, reference, created_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $introText, $slug, normaliseStatus($status), $managerId, normaliseReference($reference), $actor["id"]]);

        $inquiryId = (int) $pdo->lastInsertId();

        writeFields($pdo, $inquiryId, $fields);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new PortalWriteError($e->getMessage() ?: "Failed to save inquiry", 500);
    }

    return ["id" => $inquiryId, "slug" => $slug];
}
