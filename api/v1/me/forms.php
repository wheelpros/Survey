<?php

/*
| GET me/forms.php?status=&cursor=&limit=   my_forms
| GET me/forms.php?id=N                     get_my_form
|
| Forms released to the client: 'pending' (to fill in) and 'completed'.
| One still with a reviewer, or returned to its author, was never theirs to
| see and reads as not found - dashboard.php and survey-details.php.
| A completed form comes back with the client's own answers.
*/

require_once __DIR__ . "/_me.php";
require_once __DIR__ . "/../../survey-submit.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureSurveyColumns($pdo);

$id = v1IntParam("id");

if ($id !== null) {
    $form = loadClientSurvey($pdo, $p["id"], $id);
    if (!$form) {
        v1Error(404, "not_found", "No form of yours with that id.");
    }

    $q = $pdo->prepare("
        SELECT id, question_text, question_type, required, chips, max_file_size_mb
        FROM survey_questions WHERE survey_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $q->execute([$id]);

    $data = [
        "id" => (int) $form["id"],
        "title" => (string) $form["title"],
        // pending: waiting for you to fill it in; completed: you sent it.
        "status" => (string) $form["status"],
        "created_at" => (string) $form["created_at"],
        "description" => v1PlainText($form["description"] ?? ""),
        "questions" => array_map(function ($r) {
            $options = json_decode((string) ($r["chips"] ?? ""), true);
            return [
                "id" => (int) $r["id"],
                "text" => (string) $r["question_text"],
                // input: one line; textarea: a paragraph; checkbox: tick
                // options (or one box, when it has none); file: an upload,
                // which can only be done on the portal page.
                "type" => (string) $r["question_type"],
                "required" => (int) $r["required"] === 1,
                // For checkbox questions, the boxes; for the others,
                // suggestions the page offers as chips.
                "options" => is_array($options) ? array_values(array_map("strval", $options)) : [],
                "max_file_size_mb" => $r["max_file_size_mb"] === null ? null : (int) $r["max_file_size_mb"],
            ];
        }, $q->fetchAll()),
        "my_answers" => null,
    ];

    if ($form["status"] === "completed") {
        $a = $pdo->prepare("
            SELECT sa.question_id, sa.question_label, sa.answer
            FROM survey_answers sa
            JOIN survey_responses sr ON sr.id = sa.response_id
            WHERE sr.survey_id = ? AND sr.user_id = ?
            ORDER BY sa.id ASC
        ");
        $a->execute([$id, $p["id"]]);
        $data["my_answers"] = [
            "untrusted_content" => [
                "answers" => array_map(function ($r) {
                    return [
                        "question_id" => (int) $r["question_id"],
                        "question" => (string) $r["question_label"],
                        "answer" => (string) ($r["answer"] ?? ""),
                    ];
                }, $a->fetchAll()),
            ],
        ];
    }

    v1Reply(200, $data);
}

$limit = v1Limit();
$after = v1IdCursor();
$parts = [["assigned_user_id = ? AND status IN ('pending', 'completed')", [$p["id"]]]];
$status = v1EnumParam("status", ["pending", "completed"]);
if ($status !== null) {
    $parts[] = ["status = ?", [$status]];
}
if ($after !== null) {
    $parts[] = ["id < ?", [$after]];
}
[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare("SELECT id, title, status, created_at FROM surveys $where ORDER BY id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, function ($r) {
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        "status" => (string) $r["status"],
        "created_at" => (string) $r["created_at"],
    ];
}));
