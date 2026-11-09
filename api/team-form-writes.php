<?php

/*
|--------------------------------------------------------------------------
| Team forms: forms sent to admins, answered by admins
|--------------------------------------------------------------------------
|
| A client form (survey) belongs to one client account. A team form is the
| other direction: someone holding team_forms.send - the Director by default -
| writes the questions and sends them to the team, the way an announcement is
| sent. Each recipient fills it in once and sends it back.
|
| Who gets it is picked like an announcement, plus by role:
|   all       every active admin except the sender
|   roles     every active admin holding one of the picked roles
|   specific  the picked admins
| Roles and specific people can be combined - the union is sent to.
|
| Who sees what:
|   a recipient           the questions and their own answers
|   the sender            everything, with who has answered
|   the Director          everything (owner passes every can())
|   team_forms.responses  everything, for every team form
|
| Shared by api/team-forms.php (the pages) and api/v1 (the MCP tools), so
| both go through the same checks and the same notifications.
|
| Callers run ensureTeamFormTables() first: it is DDL, which cannot happen
| inside the transactions below.
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notify.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/survey-writes.php"; // SURVEY_QUESTION_TYPES, prepareChips()

const TEAM_FORM_SCOPES = ["all", "roles", "specific"];
const TEAM_FORM_MAX_QUESTIONS = 100;
const TEAM_FORM_MAX_FILE_MB = 5;

/** How a role is named on the pages. */
function teamFormRoleName($role)
{
    switch ($role) {
        case "owner": return "Director";
        case "super_admin": return "Super Admin";
        case "account_manager": return "Account Manager";
        case "seo_admin": return "Admin";
        default: return "";
    }
}

/**
 * Title, description, due date and questions, checked and cleaned.
 * Questions come as [{text, type, chips, maxFileSizeMb}] - the builder's shape.
 * Returns [title, description, dueDate|null, questions]. Throws PortalWriteError.
 */
function checkTeamForm($title, $description, $dueDate, $questions)
{
    $title = trim((string) $title);
    $description = trim((string) $description);
    $dueDate = trim((string) $dueDate);

    if ($title === "") {
        throw new PortalWriteError("Give the form a title.", 400);
    }
    if (mb_strlen($title) > 200) {
        throw new PortalWriteError("Keep the title to 200 characters.", 400);
    }
    if (mb_strlen($description) > 5000) {
        throw new PortalWriteError("Keep the description to 5,000 characters.", 400);
    }
    if ($dueDate !== "") {
        $d = DateTime::createFromFormat("Y-m-d", $dueDate);
        if (!$d || $d->format("Y-m-d") !== $dueDate) {
            throw new PortalWriteError("The due date must be a date like 2026-12-31.", 400);
        }
    }

    if (!is_array($questions)) {
        throw new PortalWriteError("Add at least one question.", 400);
    }

    $clean = [];
    foreach (array_values($questions) as $q) {
        $text = trim((string) ($q["text"] ?? ""));
        if ($text === "") {
            continue;
        }
        if (mb_strlen($text) > 1000) {
            throw new PortalWriteError("Keep each question to 1,000 characters.", 400);
        }

        $type = $q["type"] ?? "input";
        if ($type === "checklist") {
            $type = "checkbox";
        }
        if (!in_array($type, SURVEY_QUESTION_TYPES, true)) {
            $type = "input";
        }

        $maxMb = null;
        if ($type === "file") {
            $maxMb = (int) ($q["maxFileSizeMb"] ?? TEAM_FORM_MAX_FILE_MB);
            if ($maxMb <= 0 || $maxMb > TEAM_FORM_MAX_FILE_MB) {
                $maxMb = TEAM_FORM_MAX_FILE_MB;
            }
        }

        $chips = $type === "checkbox" ? prepareChips($q["chips"] ?? "") : json_encode([]);
        if ($type === "checkbox" && !json_decode($chips, true)) {
            throw new PortalWriteError("Give \"" . mb_substr($text, 0, 60) . "\" at least one option to tick.", 400);
        }

        $clean[] = [
            "text"     => $text,
            "type"     => $type,
            // An unticked checklist is a fair answer; everything else must be filled in.
            "required" => $type === "checkbox" ? 0 : 1,
            "chips"    => $chips,
            "max_mb"   => $maxMb,
        ];
    }

    if (!$clean) {
        throw new PortalWriteError("Add at least one question.", 400);
    }
    if (count($clean) > TEAM_FORM_MAX_QUESTIONS) {
        throw new PortalWriteError("A form can have up to " . TEAM_FORM_MAX_QUESTIONS . " questions.", 400);
    }

    return [$title, $description, $dueDate !== "" ? $dueDate : null, $clean];
}

/** The roles posted, as known role keys. Unknown ones are refused, not dropped. */
function checkTeamFormRoles($roles)
{
    if (is_string($roles)) {
        $roles = $roles === "" ? [] : explode(",", $roles);
    }
    $roles = array_values(array_unique(array_map("trim", (array) $roles)));
    $roles = array_values(array_filter($roles, "strlen"));

    foreach ($roles as $role) {
        if (!in_array($role, ADMIN_ROLES, true)) {
            throw new PortalWriteError("Unknown role: " . $role . ".", 400);
        }
    }
    return $roles;
}

/**
 * Who receives it - active admins, never the sender. $scope "all" ignores
 * $roles and $ids; otherwise the union of everyone holding one of $roles and
 * the $ids that are active admins. Throws when nobody is left.
 */
function teamFormRecipientIds(PDO $pdo, $senderId, $scope, array $roles = [], array $ids = [])
{
    if (!in_array($scope, TEAM_FORM_SCOPES, true)) {
        throw new PortalWriteError("Send it to the whole team, to roles, or to specific people.", 400);
    }

    $senderId = (int) $senderId;

    if ($scope === "all") {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE active = 1 AND id <> ? ORDER BY id");
        $stmt->execute([$senderId]);
        $found = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $ids = array_values(array_unique(array_filter(array_map("intval", $ids), function ($id) {
            return $id > 0;
        })));

        if (!$roles && !$ids) {
            throw new PortalWriteError($scope === "roles" ? "Pick at least one role." : "Pick at least one person.", 400);
        }

        $where = [];
        $args = [$senderId];
        if ($roles) {
            $where[] = "role IN (" . implode(",", array_fill(0, count($roles), "?")) . ")";
            $args = array_merge($args, $roles);
        }
        if ($ids) {
            $where[] = "id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
            $args = array_merge($args, $ids);
        }

        $stmt = $pdo->prepare("SELECT id FROM admins WHERE active = 1 AND id <> ? AND (" . implode(" OR ", $where) . ") ORDER BY id");
        $stmt->execute($args);
        $found = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$found) {
        throw new PortalWriteError("Nobody to send this to.", 400);
    }
    return $found;
}

/**
 * Saves the form, its questions and its recipients in one go, then tells the
 * recipients. Returns the new id.
 */
function createTeamForm(PDO $pdo, array $sender, $title, $description, $dueDate, array $questions, array $recipientIds, array $roles = [])
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO team_forms (title, description, due_date, status, target_roles, created_by_admin_id)
            VALUES (?, ?, ?, 'open', ?, ?)
        ")->execute([
            $title,
            $description !== "" ? $description : null,
            $dueDate,
            $roles ? implode(",", $roles) : null,
            (int) $sender["id"],
        ]);
        $formId = (int) $pdo->lastInsertId();

        $q = $pdo->prepare("
            INSERT INTO team_form_questions
                (form_id, question_text, question_type, required, sort_order, chips, max_file_size_mb)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($questions as $i => $question) {
            $q->execute([$formId, $question["text"], $question["type"], $question["required"], $i + 1, $question["chips"], $question["max_mb"]]);
        }

        $r = $pdo->prepare("INSERT INTO team_form_recipients (form_id, admin_id, status) VALUES (?, ?, 'pending')");
        foreach ($recipientIds as $id) {
            $r->execute([$formId, (int) $id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new PortalWriteError("Could not save the form.", 500);
    }

    logEvent($pdo, EVENT_TEAM_FORM_SENT, "team_form", $formId, $title,
        "Sent team form \"" . $title . "\" to " . count($recipientIds) . " " . (count($recipientIds) === 1 ? "person" : "people"));

    $who = trim((string) ($sender["name"] ?? ""));
    $body = ($who !== "" ? $who . " sent you a form to fill in" : "A form to fill in")
        . ($dueDate ? ", due " . date("j M Y", strtotime($dueDate)) : "") . ".";
    notifyTeamForm($pdo, $recipientIds, $formId, $title, $body, (int) $sender["id"]);

    return $formId;
}

/** The form row, or null. */
function teamFormRow(PDO $pdo, $formId)
{
    $stmt = $pdo->prepare("
        SELECT f.*, a.name AS sender_name
        FROM team_forms f
        LEFT JOIN admins a ON a.id = f.created_by_admin_id
        WHERE f.id = ?
    ");
    $stmt->execute([(int) $formId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Whether $admin may see every answer to $form: the sender, the Director, or team_forms.responses. */
function teamFormSeesAnswers(PDO $pdo, array $admin, array $form)
{
    return (int) $form["created_by_admin_id"] === (int) $admin["id"]
        || can($admin, "team_forms.responses", $pdo);
}

/** $admin's recipient row for the form, or null. */
function teamFormRecipientRow(PDO $pdo, $formId, $adminId)
{
    $stmt = $pdo->prepare("SELECT status, submitted_at FROM team_form_recipients WHERE form_id = ? AND admin_id = ?");
    $stmt->execute([(int) $formId, (int) $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function teamFormQuestions(PDO $pdo, $formId)
{
    $stmt = $pdo->prepare("
        SELECT id, question_text, question_type, required, chips, max_file_size_mb
        FROM team_form_questions WHERE form_id = ? ORDER BY sort_order, id
    ");
    $stmt->execute([(int) $formId]);

    return array_map(function ($q) {
        return [
            "id"               => (int) $q["id"],
            "text"             => $q["question_text"],
            "type"             => $q["question_type"],
            "required"         => (bool) $q["required"],
            "options"          => json_decode($q["chips"] ?: "[]", true) ?: [],
            "max_file_size_mb" => $q["max_file_size_mb"] !== null ? (int) $q["max_file_size_mb"] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** One person's answers: [question_id => {answer, file}] */
function teamFormAnswersOf(PDO $pdo, $formId, $adminId)
{
    $stmt = $pdo->prepare("
        SELECT id, question_id, question_label, answer, file_path
        FROM team_form_answers WHERE form_id = ? AND admin_id = ? ORDER BY id
    ");
    $stmt->execute([(int) $formId, (int) $adminId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $out[] = [
            "question_id" => (int) $a["question_id"],
            "question"    => $a["question_label"],
            "answer"      => $a["answer"],
            "file_id"     => $a["file_path"] ? (int) $a["id"] : null,
        ];
    }
    return $out;
}

function teamFormIsOverdue(array $form, $recipientStatus)
{
    return $recipientStatus === "pending" && $form["status"] === "open"
        && $form["due_date"] && $form["due_date"] < date("Y-m-d");
}

/**
 * The form as $admin may see it, or null when they may not see it at all.
 * Recipients get the questions plus their own status and answers; the sender,
 * the Director and team_forms.responses also get everyone's progress and answers.
 */
function teamFormFor(PDO $pdo, array $admin, $formId)
{
    $form = teamFormRow($pdo, $formId);
    if (!$form) {
        return null;
    }

    $seesAll = teamFormSeesAnswers($pdo, $admin, $form);
    $mine = teamFormRecipientRow($pdo, $formId, $admin["id"]);

    if (!$seesAll && !$mine) {
        return null;
    }

    $out = [
        "id"          => (int) $form["id"],
        "title"       => $form["title"],
        "description" => $form["description"] ?? "",
        "due_date"    => $form["due_date"],
        "status"      => $form["status"],
        "sent_at"     => $form["created_at"],
        "sender"      => ["id" => (int) $form["created_by_admin_id"], "name" => $form["sender_name"] ?? ""],
        "is_sender"   => (int) $form["created_by_admin_id"] === (int) $admin["id"],
        "can_close"   => (int) $form["created_by_admin_id"] === (int) $admin["id"] || $admin["role"] === "owner",
        "sees_answers" => $seesAll,
        "target_roles" => $form["target_roles"] ? array_map(function ($r) {
            return ["role" => $r, "name" => teamFormRoleName($r)];
        }, explode(",", $form["target_roles"])) : [],
        "questions"   => teamFormQuestions($pdo, $formId),
        "mine"        => $mine ? [
            "status"       => $mine["status"],
            "submitted_at" => $mine["submitted_at"],
            "overdue"      => teamFormIsOverdue($form, $mine["status"]),
            "answers"      => $mine["status"] === "submitted" ? teamFormAnswersOf($pdo, $formId, $admin["id"]) : [],
        ] : null,
    ];

    if ($seesAll) {
        $stmt = $pdo->prepare("
            SELECT r.admin_id, r.status, r.submitted_at, a.name, a.email, a.role
            FROM team_form_recipients r
            LEFT JOIN admins a ON a.id = r.admin_id
            WHERE r.form_id = ?
            ORDER BY r.status = 'submitted' DESC, r.submitted_at, a.name
        ");
        $stmt->execute([(int) $formId]);

        $out["recipients"] = array_map(function ($r) use ($pdo, $form, $formId) {
            return [
                "id"           => (int) $r["admin_id"],
                "name"         => $r["name"] ?? "Removed admin",
                "email"        => $r["email"] ?? "",
                "role"         => $r["role"] ?? "",
                "role_name"    => teamFormRoleName($r["role"] ?? ""),
                "status"       => $r["status"],
                "submitted_at" => $r["submitted_at"],
                "overdue"      => teamFormIsOverdue($form, $r["status"]),
                "answers"      => $r["status"] === "submitted" ? teamFormAnswersOf($pdo, $formId, $r["admin_id"]) : [],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $answered = count(array_filter($out["recipients"], function ($r) { return $r["status"] === "submitted"; }));
        $out["progress"] = ["answered" => $answered, "total" => count($out["recipients"])];
    }

    return $out;
}

/**
 * The forms list for $admin: what was sent to them, and what they sent (or,
 * with team_forms.responses, every form). Short rows, newest first.
 */
function teamFormsListFor(PDO $pdo, array $admin)
{
    $stmt = $pdo->prepare("
        SELECT f.id, f.title, f.due_date, f.status, f.created_at, a.name AS sender_name,
               r.status AS my_status, r.submitted_at
        FROM team_form_recipients r
        JOIN team_forms f ON f.id = r.form_id
        LEFT JOIN admins a ON a.id = f.created_by_admin_id
        WHERE r.admin_id = ?
        ORDER BY r.status = 'pending' DESC, f.id DESC
        LIMIT 200
    ");
    $stmt->execute([(int) $admin["id"]]);
    $assigned = array_map(function ($f) {
        return [
            "id"           => (int) $f["id"],
            "title"        => $f["title"],
            "sender_name"  => $f["sender_name"] ?? "",
            "due_date"     => $f["due_date"],
            "form_status"  => $f["status"],
            "status"       => $f["my_status"],
            "submitted_at" => $f["submitted_at"],
            "sent_at"      => $f["created_at"],
            "overdue"      => teamFormIsOverdue($f, $f["my_status"]),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $all = can($admin, "team_forms.responses", $pdo);
    $sql = "
        SELECT f.id, f.title, f.due_date, f.status, f.created_at, f.created_by_admin_id, a.name AS sender_name,
               COUNT(r.admin_id) AS total, SUM(r.status = 'submitted') AS answered
        FROM team_forms f
        LEFT JOIN admins a ON a.id = f.created_by_admin_id
        LEFT JOIN team_form_recipients r ON r.form_id = f.id
        " . ($all ? "" : "WHERE f.created_by_admin_id = ?") . "
        GROUP BY f.id
        ORDER BY f.id DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($all ? [] : [(int) $admin["id"]]);
    $sent = array_map(function ($f) use ($admin) {
        return [
            "id"          => (int) $f["id"],
            "title"       => $f["title"],
            "sender_name" => $f["sender_name"] ?? "",
            "is_sender"   => (int) $f["created_by_admin_id"] === (int) $admin["id"],
            "due_date"    => $f["due_date"],
            "status"      => $f["status"],
            "sent_at"     => $f["created_at"],
            "answered"    => (int) $f["answered"],
            "total"       => (int) $f["total"],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        "assigned"  => $assigned,
        "sent"      => $sent,
        "pending"   => count(array_filter($assigned, function ($f) {
            return $f["status"] === "pending" && $f["form_status"] === "open";
        })),
        "can_send"  => can($admin, "team_forms.send", $pdo),
        "sees_all"  => $all,
    ];
}

/**
 * $admin sends their answers back. $answers is [question_id => text] (a
 * checklist's ticks joined with ", "); $files is $_FILES["files"] keyed by
 * question id, as survey.html posts them. Once per person, while it is open.
 */
function submitTeamFormResponse(PDO $pdo, array $admin, $formId, array $answers, $files = null)
{
    $form = teamFormRow($pdo, $formId);
    $mine = $form ? teamFormRecipientRow($pdo, $formId, $admin["id"]) : null;

    if (!$form || !$mine) {
        throw new PortalWriteError("Form not found.", 404);
    }
    if ($mine["status"] === "submitted") {
        throw new PortalWriteError("You've already sent your answers to this form.", 409);
    }
    if ($form["status"] !== "open") {
        throw new PortalWriteError("This form is closed and no longer takes answers.", 409);
    }

    $questions = teamFormQuestions($pdo, $formId);
    $rows = [];
    $uploads = [];

    foreach ($questions as $q) {
        $qid = $q["id"];
        $text = trim((string) ($answers[$qid] ?? $answers[(string) $qid] ?? ""));

        if ($q["type"] === "file") {
            $has = $files && isset($files["name"][$qid]) && ($files["error"][$qid] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if (!$has) {
                if ($q["required"]) {
                    throw new PortalWriteError("Attach a file for \"" . mb_substr($q["text"], 0, 60) . "\".", 400);
                }
                continue;
            }
            $size = (int) $files["size"][$qid];
            $max = ($q["max_file_size_mb"] ?: TEAM_FORM_MAX_FILE_MB) * 1024 * 1024;
            if ($size > $max) {
                throw new PortalWriteError("The file for \"" . mb_substr($q["text"], 0, 60) . "\" is over " . ($q["max_file_size_mb"] ?: TEAM_FORM_MAX_FILE_MB) . " MB.", 400);
            }
            $uploads[$qid] = [
                "tmp"  => $files["tmp_name"][$qid],
                "name" => (string) $files["name"][$qid],
                "type" => (new finfo(FILEINFO_MIME_TYPE))->file($files["tmp_name"][$qid]) ?: "application/octet-stream",
            ];
            $rows[] = [$qid, $q["text"], $uploads[$qid]["name"]];
            continue;
        }

        if ($q["type"] === "checkbox") {
            // Only options the form offers survive.
            $picked = array_values(array_intersect($q["options"], array_map("trim", explode(",", $text))));
            $rows[] = [$qid, $q["text"], implode(", ", $picked)];
            continue;
        }

        if ($text === "") {
            if ($q["required"]) {
                throw new PortalWriteError("Answer \"" . mb_substr($q["text"], 0, 60) . "\".", 400);
            }
            continue;
        }
        if (mb_strlen($text) > 10000) {
            throw new PortalWriteError("Keep each answer to 10,000 characters.", 400);
        }
        $rows[] = [$qid, $q["text"], $text];
    }

    $dir = __DIR__ . "/../uploads/team-forms/" . (int) $formId . "/" . (int) $admin["id"];
    $moved = [];

    $pdo->beginTransaction();
    try {
        // Claim first: of two submits at once, the second finds it taken and saves nothing.
        $claim = $pdo->prepare("
            UPDATE team_form_recipients SET status = 'submitted', submitted_at = NOW()
            WHERE form_id = ? AND admin_id = ? AND status = 'pending'
        ");
        $claim->execute([(int) $formId, (int) $admin["id"]]);
        if ($claim->rowCount() === 0) {
            throw new PortalWriteError("You've already sent your answers to this form.", 409);
        }

        $ins = $pdo->prepare("
            INSERT INTO team_form_answers (form_id, admin_id, question_id, question_label, answer, file_path, file_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($rows as [$qid, $label, $answer]) {
            $path = null;
            $type = null;
            if (isset($uploads[$qid])) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $safe = preg_replace("/[^a-zA-Z0-9._-]/", "_", $uploads[$qid]["name"]);
                $path = $dir . "/" . bin2hex(random_bytes(8)) . "_" . $safe;
                if (!move_uploaded_file($uploads[$qid]["tmp"], $path)) {
                    throw new PortalWriteError("Could not save the file " . $uploads[$qid]["name"] . ".", 500);
                }
                $moved[] = $path;
                $type = $uploads[$qid]["type"];
            }
            $ins->execute([(int) $formId, (int) $admin["id"], $qid, $label, $answer, $path, $type]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach ($moved as $path) {
            @unlink($path);
        }
        if ($e instanceof PortalWriteError) {
            throw $e;
        }
        throw new PortalWriteError("Could not send your answers.", 500);
    }

    logEvent($pdo, EVENT_TEAM_FORM_ANSWERED, "team_form", (int) $formId, $form["title"],
        "Answered team form \"" . $form["title"] . "\"");

    $link = "admin-team-form.html?id=" . (int) $formId;
    notify($pdo, "admin", (int) $form["created_by_admin_id"], NOTIFY_TEAM_FORM_ANSWERED,
        ($admin["name"] ?? "Someone") . " answered " . $form["title"], "",
        $link, "admin", (int) $admin["id"]);

    // notify() has no team_form_id; set it so an assistant can open the form
    // (get_team_form) from the notification, as it can from the one sent out.
    try {
        $pdo->prepare("
            UPDATE notifications SET team_form_id = ?
            WHERE recipient_kind = 'admin' AND recipient_id = ? AND event_type = ? AND link = ? AND team_form_id IS NULL
        ")->execute([(int) $formId, (int) $form["created_by_admin_id"], NOTIFY_TEAM_FORM_ANSWERED, $link]);
    } catch (PDOException $e) {
        // The notification still links to the form; only the id is missing.
    }
}

/** Close or reopen. The sender or the Director. */
function setTeamFormOpen(PDO $pdo, array $admin, $formId, $open)
{
    $form = teamFormRow($pdo, $formId);
    if (!$form || (!teamFormRecipientRow($pdo, $formId, $admin["id"]) && !teamFormSeesAnswers($pdo, $admin, $form))) {
        throw new PortalWriteError("Form not found.", 404);
    }
    if ((int) $form["created_by_admin_id"] !== (int) $admin["id"] && $admin["role"] !== "owner") {
        throw new PortalWriteError("Only whoever sent this form can close or reopen it.", 403);
    }

    $pdo->prepare("UPDATE team_forms SET status = ?, closed_at = ? WHERE id = ?")
        ->execute([$open ? "open" : "closed", $open ? null : date("Y-m-d H:i:s"), (int) $formId]);

    logEvent($pdo, EVENT_TEAM_FORM_CLOSED, "team_form", (int) $formId, $form["title"],
        ($open ? "Reopened" : "Closed") . " team form \"" . $form["title"] . "\"");
}

/** One uploaded answer file, if $admin may see it: [path, type, name] or null. */
function teamFormFileFor(PDO $pdo, array $admin, $answerId)
{
    $stmt = $pdo->prepare("SELECT form_id, admin_id, answer, file_path, file_type FROM team_form_answers WHERE id = ? AND file_path IS NOT NULL");
    $stmt->execute([(int) $answerId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        return null;
    }

    $form = teamFormRow($pdo, $a["form_id"]);
    $own = (int) $a["admin_id"] === (int) $admin["id"];
    if (!$form || (!$own && !teamFormSeesAnswers($pdo, $admin, $form)) || !is_file($a["file_path"])) {
        return null;
    }
    return [$a["file_path"], $a["file_type"] ?: "application/octet-stream", $a["answer"]];
}

/** The answers as CSV rows: a header, then one row per person who answered. */
function teamFormCsvRows(array $form)
{
    $head = array_merge(["Name", "Email", "Role", "Submitted"], array_column($form["questions"], "text"));
    $rows = [$head];
    foreach ($form["recipients"] as $r) {
        if ($r["status"] !== "submitted") {
            continue;
        }
        $byQ = [];
        foreach ($r["answers"] as $a) {
            $byQ[$a["question_id"]] = $a["answer"];
        }
        $row = [$r["name"], $r["email"], $r["role_name"], $r["submitted_at"]];
        foreach ($form["questions"] as $q) {
            $row[] = $byQ[$q["id"]] ?? "";
        }
        $rows[] = $row;
    }
    return $rows;
}

/** The people and roles the picker offers: active admins except $senderId. */
function teamFormRecipientChoices(PDO $pdo, $senderId)
{
    $stmt = $pdo->prepare("
        SELECT id, name, email, role
        FROM admins
        WHERE active = 1 AND id <> ?
        ORDER BY FIELD(role, 'owner', 'super_admin', 'account_manager', 'seo_admin'), name
    ");
    $stmt->execute([(int) $senderId]);

    $people = [];
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $people[] = [
            "id"        => (int) $a["id"],
            "name"      => $a["name"],
            "email"     => $a["email"],
            "role"      => $a["role"],
            "role_name" => teamFormRoleName($a["role"]),
        ];
        $counts[$a["role"]] = ($counts[$a["role"]] ?? 0) + 1;
    }

    $roles = [];
    foreach (ADMIN_ROLES as $role) {
        $roles[] = ["role" => $role, "name" => teamFormRoleName($role), "count" => $counts[$role] ?? 0];
    }

    return ["people" => $people, "roles" => $roles];
}
