<?php

/*
|--------------------------------------------------------------------------
| api/v1 - the changes an AI assistant can make, and how each is checked
|--------------------------------------------------------------------------
|
| Every change goes prepare -> the person confirms -> confirm (changes.php).
| A handler here is two functions:
|
|   prepare($pdo, $p, $args)  checks the request as this person, against
|       the portal as it is now, and returns what will happen:
|       ["args" => cleaned arguments, "summary" => one plain paragraph the
|        person approves, "preview" => the details, structured]
|       Nothing is written.
|   apply($pdo, $p, $args)    makes the change with the cleaned arguments
|       and returns its result. Run only on confirm, after prepare has
|       been run again - so a form someone reviewed in the meantime, or a
|       client taken off this admin, stops the change.
|
| The writes themselves are the browser endpoints' own shared functions
| (survey-writes.php, meeting-writes.php, task-writes.php,
| inquiry-templates.php, setClientNote() in db.php), so what an assistant
| does lands exactly as the same thing done on the page: same review gate,
| same notifications, same emails.
|
| Deliberately missing: anything that deletes, publishes content, sends an
| announcement, or changes accounts or permissions. Those stay in the portal.
*/

require_once __DIR__ . "/../survey-writes.php";
require_once __DIR__ . "/../meeting-writes.php";
require_once __DIR__ . "/../task-writes.php";
require_once __DIR__ . "/../inquiry-templates.php";

const V1_CONFIRM_TTL = 900; // 15 minutes to say yes

function ensureMcpConfirmations(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    /*
    | One row per prepared change. The arguments are stored here rather than
    | sent back, so what is applied is exactly what was checked and shown -
    | the assistant can't alter it between the two steps. Kept after use as
    | the record of what an assistant changed, for whom, through which
    | connection (grant_id), with its result.
    */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mcp_confirmations (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            token_hash     CHAR(64)     NOT NULL,
            principal_kind VARCHAR(10)  NOT NULL,
            principal_id   INT          NOT NULL,
            grant_id       INT              NULL,
            tool           VARCHAR(60)  NOT NULL,
            args           TEXT         NOT NULL,
            summary        TEXT         NOT NULL,
            expires_at     INT          NOT NULL,
            used_at        INT              NULL,
            result         TEXT             NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token (token_hash),
            KEY idx_principal (principal_kind, principal_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/*
|--------------------------------------------------------------------------
| Reading arguments
|--------------------------------------------------------------------------
|
| Each throws PortalWriteError (422) naming the argument, so the assistant
| can fix the one thing and prepare again.
*/

function argInt(array $a, $key, $required = true)
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        if ($required) {
            throw new PortalWriteError("$key is required.");
        }
        return null;
    }
    if (!is_int($a[$key]) || $a[$key] <= 0) {
        throw new PortalWriteError("$key must be a positive whole number.");
    }
    return $a[$key];
}

function argText(array $a, $key, $max, $required = true)
{
    $value = $a[$key] ?? null;
    if ($value !== null && !is_string($value)) {
        throw new PortalWriteError("$key must be text.");
    }
    $value = trim((string) $value);
    if ($required && $value === "") {
        throw new PortalWriteError("$key is required.");
    }
    if (mb_strlen($value) > $max) {
        throw new PortalWriteError("$key must be $max characters or fewer (it is " . mb_strlen($value) . ").");
    }
    return $value;
}

function argEnum(array $a, $key, array $allowed, $default = null)
{
    $value = $a[$key] ?? $default;
    if (!in_array($value, $allowed, true)) {
        throw new PortalWriteError("$key must be one of: " . implode(", ", $allowed) . ".");
    }
    return $value;
}

function argBool(array $a, $key, $default)
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        return $default;
    }
    if (!is_bool($a[$key])) {
        throw new PortalWriteError("$key must be true or false.");
    }
    return $a[$key];
}

/** YYYY-MM-DD, or null when absent or "". */
function argDate(array $a, $key)
{
    $value = trim((string) ($a[$key] ?? ""));
    if ($value === "") {
        return null;
    }
    $d = DateTime::createFromFormat("!Y-m-d", $value);
    if (!$d || $d->format("Y-m-d") !== $value) {
        throw new PortalWriteError("$key must be a date like 2026-10-05.");
    }
    return $value;
}

/** HH:MM, or null when absent or "". */
function argTime(array $a, $key)
{
    $value = trim((string) ($a[$key] ?? ""));
    if ($value === "") {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
        throw new PortalWriteError("$key must be a 24-hour time like 14:30.");
    }
    return $value;
}

function argList(array $a, $key, $min, $max)
{
    $value = $a[$key] ?? null;
    if (!is_array($value) || array_values($value) !== $value) {
        throw new PortalWriteError("$key must be a list.");
    }
    if (count($value) < $min || count($value) > $max) {
        throw new PortalWriteError("$key must have between $min and $max entries.");
    }
    return $value;
}

/** What the assistant wrote as plain text, as the HTML the portal's editors store. */
function textToHtml($text)
{
    $paragraphs = preg_split("/\n\s*\n/", str_replace("\r", "", trim((string) $text)));
    return implode("", array_map(function ($para) {
        return "<p>" . nl2br(htmlspecialchars(trim($para), ENT_QUOTES, "UTF-8"), false) . "</p>";
    }, array_filter($paragraphs, function ($para) {
        return trim($para) !== "";
    })));
}

/** "Alice (Acme Ltd)" - how a client is named in a summary. */
function clientLabelFor(array $row)
{
    $company = trim((string) ($row["company_name"] ?? ""));
    return $company !== "" ? "{$row["name"]} ($company)" : (string) $row["name"];
}

function quoteFor($text, $max = 120)
{
    $text = trim(preg_replace('/\s+/', " ", (string) $text));
    return "\"" . (mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . "…" : $text) . "\"";
}

/** A meeting the admin may answer: a client's request, from one of their clients. */
function loadClientRequest(PDO $pdo, array $p, $id)
{
    [$scopeSql, $scopeParams] = v1ClientScopeSql($p, "a.user_id");
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.date, a.time, a.status, a.topic, a.title, u.name, u.company_name
        FROM appointments a
        JOIN users u ON u.id = a.user_id
        WHERE a.id = ? AND a.requested_by = 'user'" . ($scopeSql !== "" ? " AND $scopeSql" : "") . "
        LIMIT 1
    ");
    $stmt->execute(array_merge([$id], $scopeParams));
    $row = $stmt->fetch();
    if (!$row) {
        throw new PortalWriteError("No meeting request from your clients with that id.", 404);
    }
    return $row;
}

/** A task with its (visible) project, ready to edit. */
function loadEditableTask(PDO $pdo, array $p, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    $project = $task ? loadScopedProject($pdo, $p, $task["project_id"]) : null;
    if (!$task || !$project) {
        throw new PortalWriteError("No task with that id on the projects you can see.", 404);
    }
    if (!canWriteTask($pdo, $p, $project)) {
        throw new PortalWriteError("You are not on this project's team.", 403);
    }
    if (!canEditTask($p, $task)) {
        throw new PortalWriteError("You can only edit your own tasks.", 403);
    }
    return [$task, $project];
}

/*
|--------------------------------------------------------------------------
| The handlers
|--------------------------------------------------------------------------
*/

function v1WriteHandlers()
{
    return [

        // ── Clients ──────────────────────────────────────────────────────

        "save_my_client_note" => [
            "scope" => "clients:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $text = argText($a, "text", 5000, false);
                $client = v1VisibleClient($pdo, $p, $clientId);
                $before = getClientNote($pdo, $clientId, $p["id"]);
                return [
                    "args" => ["client" => $clientId, "text" => $text],
                    "summary" => $text === ""
                        ? "Clear your private note about " . clientLabelFor($client) . "."
                        : "Replace your private note about " . clientLabelFor($client) . " with " . quoteFor($text, 200)
                          . ". Only you can see it.",
                    "preview" => [
                        "client" => v1ClientRef($client["id"], $client["name"], $client["company_name"]),
                        "untrusted_content" => ["note_now" => $before, "note_after" => $text],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                if (!setClientNote($pdo, $a["client"], $p["id"], $a["text"])) {
                    throw new PortalWriteError("Could not save the note.", 500);
                }
                return ["client_id" => $a["client"], "saved" => true];
            },
        ],

        // ── Forms ────────────────────────────────────────────────────────

        "create_form_draft" => [
            "scope" => "forms:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $title = argText($a, "title", 255);
                $description = argText($a, "description", 5000, false);
                $questions = [];
                foreach (argList($a, "questions", 1, 50) as $i => $q) {
                    $n = $i + 1;
                    if (!is_array($q)) {
                        throw new PortalWriteError("questions[$n] must be an object.");
                    }
                    $type = argEnum($q, "type", SURVEY_QUESTION_TYPES, "input");
                    $options = [];
                    if (isset($q["options"])) {
                        foreach (argList($q, "options", 0, 20) as $o) {
                            if (!is_string($o) || trim($o) === "" || mb_strlen($o) > 100) {
                                throw new PortalWriteError("questions[$n].options must be short, non-empty texts.");
                            }
                            $options[] = trim($o);
                        }
                    }
                    if ($type === "checkbox" && count($options) < 2) {
                        throw new PortalWriteError("questions[$n] is a checkbox question: give it at least two options.");
                    }
                    if ($type !== "checkbox" && $options) {
                        throw new PortalWriteError("questions[$n]: only checkbox questions take options.");
                    }
                    $maxMb = null;
                    if (isset($q["max_file_size_mb"])) {
                        if ($type !== "file" || !is_int($q["max_file_size_mb"]) || $q["max_file_size_mb"] < 1 || $q["max_file_size_mb"] > 50) {
                            throw new PortalWriteError("questions[$n].max_file_size_mb is for file questions, 1 to 50.");
                        }
                        $maxMb = $q["max_file_size_mb"];
                    }
                    $questions[] = [
                        "text" => argText($q, "text", 1000),
                        "type" => $type,
                        "options" => $options,
                        "max_file_size_mb" => $maxMb,
                    ];
                }
                $client = v1VisibleClient($pdo, $p, $clientId);
                return [
                    "args" => ["client" => $clientId, "title" => $title, "description" => $description, "questions" => $questions],
                    "summary" => "Create the form " . quoteFor($title) . " for " . clientLabelFor($client)
                        . " with " . count($questions) . (count($questions) === 1 ? " question" : " questions")
                        . ". It goes into the review queue: the client sees it only after a reviewer approves it.",
                    "preview" => [
                        "client" => v1ClientRef($client["id"], $client["name"], $client["company_name"]),
                        "title" => $title,
                        "description" => $description,
                        "questions" => $questions,
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureSurveyColumns($pdo);
                ensureNotificationsTable($pdo);
                $created = createSurveyForm($pdo, $p, $a["title"], $a["description"], $a["client"], array_map(function ($q) {
                    return ["text" => $q["text"], "type" => $q["type"], "chips" => $q["options"], "maxFileSizeMb" => $q["max_file_size_mb"]];
                }, $a["questions"]), true);
                return ["form_id" => $created["id"], "status" => $created["status"]];
            },
        ],

        "review_form" => [
            "scope" => "forms:review",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $decision = argEnum($a, "decision", ["approve", "return"]);
                $comment = argText($a, "comment", 1000, $decision === "return");
                $stmt = $pdo->prepare("
                    SELECT s.id, s.title, s.status, u.name, u.company_name, creator.name AS author
                    FROM surveys s
                    JOIN users u ON u.id = s.assigned_user_id
                    LEFT JOIN admins creator ON creator.id = s.created_by_admin_id
                    WHERE s.id = ? LIMIT 1
                ");
                $stmt->execute([$id]);
                $form = $stmt->fetch();
                if (!$form) {
                    throw new PortalWriteError("No form with that id.", 404);
                }
                if ($form["status"] !== "pending_review") {
                    throw new PortalWriteError("That form isn't waiting for review (it is {$form["status"]}).", 409);
                }
                return [
                    "args" => ["id" => $id, "decision" => $decision, "comment" => $comment],
                    "summary" => $decision === "approve"
                        ? "Approve the form " . quoteFor($form["title"]) . ". It is released to " . clientLabelFor($form)
                          . " at once, and they and its author are notified."
                        : "Return the form " . quoteFor($form["title"]) . " to its author" . ($form["author"] ? " " . $form["author"] : "")
                          . " with the comment " . quoteFor($comment, 300) . ". The client never sees it.",
                    "preview" => [
                        "form" => ["id" => (int) $form["id"], "title" => (string) $form["title"]],
                        "client" => clientLabelFor($form),
                        "decision" => $decision,
                        "comment" => $comment,
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                reviewSurveyForm($pdo, $p, $a["id"], $a["decision"] === "approve" ? "approve" : "reject", $a["comment"]);
                return ["form_id" => $a["id"], "status" => $a["decision"] === "approve" ? "pending" : "rejected"];
            },
        ],

        // ── Inquiries ────────────────────────────────────────────────────

        "create_inquiry_draft" => [
            "scope" => "inquiries:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $title = argText($a, "title", 200);
                $intro = argText($a, "intro", 2000, false);
                $fields = [];
                foreach (argList($a, "fields", 1, 30) as $i => $f) {
                    if (!is_array($f)) {
                        throw new PortalWriteError("fields[" . ($i + 1) . "] must be an object.");
                    }
                    $fields[] = [
                        "label" => argText($f, "label", 200),
                        "type" => argEnum($f, "type", INQUIRY_FIELD_TYPES, "input"),
                        "required" => argBool($f, "required", true),
                    ];
                }
                return [
                    "args" => ["title" => $title, "intro" => $intro, "fields" => $fields],
                    "summary" => "Create the consultation inquiry " . quoteFor($title) . " with " . count($fields)
                        . (count($fields) === 1 ? " question" : " questions")
                        . ". It is created closed: nobody can answer it until it is opened on the Inquiries page.",
                    "preview" => ["title" => $title, "intro" => $intro, "fields" => $fields],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureInquiryTables($pdo);
                $created = createInquiry($pdo, $p, $a["title"], $a["intro"], $a["fields"], "inactive", null, null);
                return ["inquiry_id" => $created["id"], "name" => $created["slug"], "status" => "inactive"];
            },
        ],

        // ── Content ──────────────────────────────────────────────────────

        "create_content_draft" => [
            "scope" => "content:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $types = [];
                foreach (CONTENT_TYPE_TITLES as $label) {
                    $types[trim(preg_replace("/[^a-z0-9]+/", "_", strtolower($label)), "_")] = $label;
                }
                $type = argEnum($a, "type", array_keys($types));
                $title = argText($a, "title", 255);
                // The page's own limit, counted on the text (admin-content.php).
                $caption = argText($a, "caption", 10000, false);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($time !== null && $date === null) {
                    throw new PortalWriteError("A time needs a date.");
                }
                $link = normalisePortalLink($a["link"] ?? "");
                $client = v1VisibleClient($pdo, $p, $clientId);
                $company = trim((string) ($client["company_name"] ?? ""));
                if ($company === "") {
                    throw new PortalWriteError("Posts are tied to a client by company name, and " . $client["name"] . " has none yet.");
                }
                return [
                    "args" => ["client" => $clientId, "company" => $company, "type" => $type, "type_label" => $types[$type],
                               "title" => $title, "caption" => $caption, "date" => $date, "time" => $time, "link" => $link],
                    "summary" => "Save a draft " . $types[$type] . " post " . quoteFor($title) . " for $company"
                        . ($date ? ", dated $date" . ($time ? " $time" : "") : "")
                        . ". It stays a draft: nothing is published until someone schedules it in the portal.",
                    "preview" => [
                        "client" => $company, "type" => $types[$type], "title" => $title,
                        "date" => $date, "time" => $time, "link" => $link,
                        "untrusted_content" => ["caption" => $caption],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureContentColumns($pdo);
                // The columns admin-content-form.html fills for a type: its
                // name as label, platform and category; never published here.
                $pdo->prepare("
                    INSERT INTO content
                        (title, client, link, caption, content_type, type_label, platform, category,
                         orientation, media_path, post_date, post_time, publish_now, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'horizontal', NULL, ?, ?, 0, 'draft', ?)
                ")->execute([
                    $a["title"], $a["company"], $a["link"], $a["caption"] !== "" ? textToHtml($a["caption"]) : null,
                    $a["type"], $a["type_label"], $a["type_label"], $a["type_label"],
                    $a["date"], $a["time"] !== null ? $a["time"] . ":00" : null, $p["id"],
                ]);
                return ["content_id" => (int) $pdo->lastInsertId(), "status" => "draft"];
            },
        ],

        // ── Calendar ─────────────────────────────────────────────────────

        "propose_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $clientId = argInt($a, "client");
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                if ($date === null || $time === null) {
                    throw new PortalWriteError("date and time are required.");
                }
                if ($date < date("Y-m-d")) {
                    throw new PortalWriteError("That date has passed.");
                }
                $topic = argText($a, "topic", 200);
                $notes = argText($a, "notes", 2000, false);
                $client = v1VisibleClient($pdo, $p, $clientId);
                $company = trim((string) ($client["company_name"] ?? ""));
                // Like the page: the request goes to every account at the
                // company that this admin can reach.
                $targets = $company !== ""
                    ? meetingTargetsForCompany($pdo, $p, $company)
                    : [["id" => $client["id"], "name" => $client["name"], "email" => $client["email"], "company_name" => null]];
                return [
                    "args" => ["client" => $clientId, "company" => $company, "date" => $date, "time" => $time, "topic" => $topic, "notes" => $notes],
                    "summary" => "Ask " . ($company !== "" ? $company : $client["name"]) . " for a meeting on $date at $time about " . quoteFor($topic)
                        . ". " . implode(", ", array_column($targets, "name"))
                        . (count($targets) === 1 ? " is" : " are") . " notified in the portal and by email, and can accept or decline.",
                    "preview" => [
                        "recipients" => array_map(function ($t) {
                            return v1ClientRef($t["id"], $t["name"], $t["company_name"] ?? null);
                        }, $targets),
                        "date" => $date, "time" => $time,
                        "untrusted_content" => ["topic" => $topic, "notes" => $notes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureAppointmentTables($pdo);
                ensureNotificationsTable($pdo);
                $targets = $a["company"] !== ""
                    ? meetingTargetsForCompany($pdo, $p, $a["company"])
                    : [v1VisibleClient($pdo, $p, $a["client"])];
                $ids = requestMeetingFromClients($pdo, $p, $targets, $a["company"], $a["date"], $a["time"], $a["topic"], $a["notes"]);
                return ["meeting_ids" => $ids];
            },
        ],

        "respond_to_meeting" => [
            "scope" => "calendar:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                $decision = argEnum($a, "decision", ["accept", "decline"]);
                $request = loadClientRequest($pdo, $p, $id);
                if ($request["status"] !== "pending") {
                    throw new PortalWriteError("That request has already been answered ({$request["status"]}).", 409);
                }
                $topic = (string) ($request["topic"] ?? $request["title"] ?? "");
                return [
                    "args" => ["id" => $id, "decision" => $decision],
                    "summary" => ($decision === "accept" ? "Confirm " : "Decline ") . clientLabelFor($request)
                        . "'s meeting request for {$request["date"]} at " . substr((string) $request["time"], 0, 5)
                        . " about " . quoteFor($topic) . ". They are notified.",
                    "preview" => [
                        "meeting_id" => $id,
                        "client" => clientLabelFor($request),
                        "date" => (string) $request["date"],
                        "time" => substr((string) $request["time"], 0, 5),
                        "decision" => $decision,
                        "untrusted_content" => ["topic" => $topic],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureNotificationsTable($pdo);
                answerClientMeetingRequest($pdo, $p, $a["id"], $a["decision"] === "accept" ? "approved" : "rejected", true);
                return ["meeting_id" => $a["id"], "status" => $a["decision"] === "accept" ? "approved" : "rejected"];
            },
        ],

        // ── Projects ─────────────────────────────────────────────────────

        "post_project_update" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $projectId = argInt($a, "project");
                $title = argText($a, "title", 200);
                $body = argText($a, "body", TASK_DESCRIPTION_MAX_CHARS, false);
                $date = argDate($a, "date");
                $time = argTime($a, "time");
                $link = normalisePortalLink($a["link"] ?? "");
                $publish = argBool($a, "publish", true);
                $project = loadScopedProject($pdo, $p, $projectId);
                if (!$project) {
                    throw new PortalWriteError("No project with that id among the projects you can see.", 404);
                }
                if (!canWriteTask($pdo, $p, $project)) {
                    throw new PortalWriteError("You are not on this project's team.", 403);
                }
                $client = clientLabelFor(["name" => $project["client_name"] ?? "the client", "company_name" => $project["company_name"]]);
                $later = $date !== null && $date > date("Y-m-d");
                return [
                    "args" => ["project" => $projectId, "title" => $title, "body" => $body, "date" => $date, "time" => $time,
                               "link" => $link, "publish" => $publish],
                    "summary" => $publish
                        ? "Publish the update " . quoteFor($title) . " on the project " . quoteFor($project["title"]) . ". "
                          . ($later ? "$client can see it from $date, and is notified once it is live there."
                                    : "$client can see it straight away, and is notified.")
                        : "Save the update " . quoteFor($title) . " on " . quoteFor($project["title"])
                          . " as a draft. Only the project team can see it.",
                    "preview" => [
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "client" => $client,
                        "title" => $title, "date" => $date, "time" => $time, "link" => $link,
                        "status" => $publish ? "published" : "draft",
                        "untrusted_content" => ["body" => $body],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                ensureNotificationsTable($pdo);
                $project = loadScopedProject($pdo, $p, $a["project"]);
                $id = saveProjectTask($pdo, $p, $project, null, [
                    "title" => $a["title"],
                    "link" => $a["link"],
                    "description" => $a["body"] !== "" ? textToHtml($a["body"]) : "",
                    "scheduled_date" => $a["date"],
                    "scheduled_time" => $a["time"] !== null ? $a["time"] . ":00" : null,
                    "status" => $a["publish"] ? "published" : "draft",
                ]);
                return [
                    "task_id" => $id,
                    "status" => $a["publish"] ? "published" : "draft",
                    "client_notified" => announceTaskIfLive($pdo, $p, $project, $id, $a["title"]),
                ];
            },
        ],

        "update_task" => [
            "scope" => "projects:write",
            "prepare" => function (PDO $pdo, array $p, array $a) {
                $id = argInt($a, "id");
                [$task, $project] = loadEditableTask($pdo, $p, $id);
                $args = ["id" => $id];
                $changes = [];
                $change = function ($field, $from, $to) use (&$changes) {
                    if ((string) $from !== (string) $to) {
                        $changes[] = ["field" => $field, "from" => $from, "to" => $to];
                    }
                };
                if (array_key_exists("title", $a)) {
                    $args["title"] = argText($a, "title", 200);
                    $change("title", $task["title"], $args["title"]);
                }
                if (array_key_exists("body", $a)) {
                    $args["body"] = argText($a, "body", TASK_DESCRIPTION_MAX_CHARS, false);
                    $change("body", v1PlainText($task["description"] ?? ""), $args["body"]);
                }
                if (array_key_exists("date", $a)) {
                    $args["date"] = argDate($a, "date");
                    $change("date", $task["scheduled_date"], $args["date"]);
                }
                if (array_key_exists("time", $a)) {
                    $args["time"] = argTime($a, "time");
                    $change("time", $task["scheduled_time"] === null ? null : substr($task["scheduled_time"], 0, 5), $args["time"]);
                }
                if (array_key_exists("link", $a)) {
                    $args["link"] = normalisePortalLink($a["link"]);
                    $change("link", $task["link"], $args["link"]);
                }
                if (array_key_exists("status", $a)) {
                    $args["status"] = argEnum($a, "status", ["draft", "published"]);
                    $change("status", $task["status"], $args["status"]);
                }
                if (array_key_exists("is_complete", $a)) {
                    $args["is_complete"] = argBool($a, "is_complete", false);
                    $change("is_complete", (int) $task["is_complete"] === 1 ? "true" : "false", $args["is_complete"] ? "true" : "false");
                }
                if (!$changes) {
                    throw new PortalWriteError("Nothing to change - every value given is what the task already has.");
                }
                $client = clientLabelFor(["name" => $project["client_name"] ?? "the client", "company_name" => $project["company_name"]]);
                $nowDraft = ($args["status"] ?? $task["status"]) === "draft";
                return [
                    "args" => $args,
                    "summary" => "Change the update " . quoteFor($task["title"]) . " on " . quoteFor($project["title"]) . ": "
                        . implode("; ", array_map(function ($c) {
                            return "{$c["field"]} " . ($c["from"] === null || $c["from"] === "" ? "(empty)" : quoteFor($c["from"], 60))
                                . " → " . ($c["to"] === null || $c["to"] === "" ? "(empty)" : quoteFor($c["to"], 60));
                        }, $changes))
                        . ". " . ($nowDraft ? "It stays a draft only the team sees." : "$client can see it once its date has come."),
                    "preview" => [
                        "task_id" => $id,
                        "project" => ["id" => (int) $project["id"], "title" => (string) $project["title"]],
                        "untrusted_content" => ["changes" => $changes],
                    ],
                ];
            },
            "apply" => function (PDO $pdo, array $p, array $a) {
                ensureProjectTables($pdo);
                ensureNotificationsTable($pdo);
                [$task, $project] = loadEditableTask($pdo, $p, $a["id"]);
                $title = $a["title"] ?? $task["title"];
                saveProjectTask($pdo, $p, $project, $task, [
                    "title" => $title,
                    "link" => array_key_exists("link", $a) ? $a["link"] : $task["link"],
                    "description" => array_key_exists("body", $a)
                        ? ($a["body"] !== "" ? textToHtml($a["body"]) : "")
                        : (string) ($task["description"] ?? ""),
                    "orientation" => $task["orientation"],
                    "image_path" => $task["image_path"],
                    "scheduled_date" => array_key_exists("date", $a) ? $a["date"] : $task["scheduled_date"],
                    "scheduled_time" => array_key_exists("time", $a)
                        ? ($a["time"] !== null ? $a["time"] . ":00" : null)
                        : $task["scheduled_time"],
                    "is_complete" => $a["is_complete"] ?? ((int) $task["is_complete"] === 1),
                    "status" => $a["status"] ?? $task["status"],
                ]);
                return [
                    "task_id" => (int) $a["id"],
                    "client_notified" => announceTaskIfLive($pdo, $p, $project, $a["id"], $title),
                ];
            },
        ],
    ];
}
