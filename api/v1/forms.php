<?php

/*
| Forms (surveys) - what admins build for a client, before and after review.
|
|   GET forms.php?client=&status=&cursor=&limit=   list_forms
|   GET forms.php?id=N                             get_form
|   GET forms.php?queue=1&cursor=&limit=           list_forms_awaiting_review
|
| Scope forms:read. Same rule as admin-surveys.php and admin-survey-review.php:
| the owner and account managers (the reviewers) see every form; everyone else
| only the forms they wrote. In the queue that means a reviewer sees what is
| waiting on them, and anyone else sees their own forms still waiting.
|
| Answers are in responses.php.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "forms:read");

ensureSurveyColumns($pdo);
ensureUserProfileColumns($pdo);

const FORM_STATUSES = ["pending_review", "pending", "rejected", "completed"];

$seesAll = v1SeesAllForms($p);
$ownSql = $seesAll ? ["", []] : ["s.created_by_admin_id = ?", [$p["id"]]];

const FORM_LIST_SQL = "
    SELECT s.id, s.title, s.description, s.status, s.created_at, s.reviewed_at, s.review_note,
           s.assigned_user_id, u.name AS user_name, u.company_name,
           creator.name AS created_by_name, reviewer.name AS reviewed_by_name,
           (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) AS response_count
    FROM surveys s
    JOIN users u ON u.id = s.assigned_user_id
    LEFT JOIN admins creator ON creator.id = s.created_by_admin_id
    LEFT JOIN admins reviewer ON reviewer.id = s.reviewed_by_admin_id
";

function formItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        // pending_review: waiting on a reviewer, invisible to the client.
        // pending: released, waiting on the client. rejected: returned to
        // its author. completed: the client answered it.
        "status" => (string) $r["status"],
        "client" => v1ClientRef($r["assigned_user_id"], $r["user_name"], $r["company_name"]),
        "created_by_name" => v1NullableString($r["created_by_name"]),
        "reviewed_by_name" => v1NullableString($r["reviewed_by_name"]),
        "reviewed_at" => v1NullableString($r["reviewed_at"]),
        // A reviewer's reason for returning it.
        "review_note" => v1NullableString($r["review_note"]),
        "response_count" => (int) $r["response_count"],
        "created_at" => (string) $r["created_at"],
    ];
}

$id = v1IntParam("id");

if ($id !== null) {
    [$where, $params] = v1Where([["s.id = ?", [$id]], $ownSql]);
    $stmt = $pdo->prepare(FORM_LIST_SQL . $where . " LIMIT 1");
    $stmt->execute($params);
    $form = $stmt->fetch();

    if (!$form) {
        v1Error(404, "not_found", "No form with that id among the forms you can see.");
    }

    $q = $pdo->prepare("
        SELECT id, question_text, question_type, required, chips, max_file_size_mb
        FROM survey_questions
        WHERE survey_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $q->execute([$id]);

    $data = formItem($form);
    $data["description"] = v1PlainText($form["description"] ?? "");
    $data["questions"] = array_map(function ($r) {
        $options = json_decode((string) ($r["chips"] ?? ""), true);
        return [
            "id" => (int) $r["id"],
            "text" => (string) $r["question_text"],
            // input | textarea | checkbox (pick from options) | file
            "type" => (string) $r["question_type"],
            "required" => (int) $r["required"] === 1,
            "options" => is_array($options) ? array_values(array_map("strval", $options)) : [],
            "max_file_size_mb" => $r["max_file_size_mb"] === null ? null : (int) $r["max_file_size_mb"],
        ];
    }, $q->fetchAll());

    v1Reply(200, $data);
}

$limit = v1Limit();
$after = v1IdCursor();
$parts = [$ownSql];

if (!empty($_GET["queue"])) {
    $parts[] = ["s.status = 'pending_review'", []];
} else {
    $status = v1EnumParam("status", FORM_STATUSES);
    if ($status !== null) {
        $parts[] = ["s.status = ?", [$status]];
    }
    $client = v1IntParam("client");
    if ($client !== null) {
        v1VisibleClient($pdo, $p, $client);
        $parts[] = ["s.assigned_user_id = ?", [$client]];
    }
}

if ($after !== null) {
    $parts[] = ["s.id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare(FORM_LIST_SQL . $where . " ORDER BY s.id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);

$page = v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, "formItem");

if (!empty($_GET["queue"])) {
    // Whether this person can approve or return what is listed.
    $page["can_review"] = hasScope($p, "forms:review");
}

v1Reply(200, $page);
