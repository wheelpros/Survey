<?php

/*
| Team forms - forms admins send each other (api/team-form-writes.php).
|
|   GET team-forms.php        list_team_forms   sent to me, and the ones I sent
|   GET team-forms.php?id=N   get_team_form     one, as I may see it
|
| Scope team_forms:read. The same rule as the pages: a recipient sees the
| questions and their own answers; the sender, the Director and anyone with
| team_forms.responses see everyone's. Anyone else gets a 404.
|
| Sending, answering and closing go through prepare/confirm (send_team_form,
| answer_team_form, close_team_form in _writes.php).
*/

require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/../team-form-writes.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "team_forms:read");

ensureTeamFormTables($pdo);

/** Answers as data someone typed. */
function v1TeamFormAnswers(array $answers)
{
    return array_map(function ($a) {
        return [
            "question_id" => $a["question_id"],
            "has_file" => $a["file_id"] !== null,
            "untrusted_content" => ["question" => (string) $a["question"], "answer" => (string) ($a["answer"] ?? "")],
        ];
    }, $answers);
}

$id = v1IntParam("id");

if ($id === null) {
    $list = teamFormsListFor($pdo, $p);
    v1Reply(200, [
        "assigned" => array_map(function ($f) {
            return [
                "id" => $f["id"],
                "sender_name" => (string) $f["sender_name"],
                "due_date" => v1NullableString($f["due_date"]),
                "form_status" => $f["form_status"],
                "status" => $f["status"],
                "overdue" => (bool) $f["overdue"],
                "sent_at" => (string) $f["sent_at"],
                "untrusted_content" => ["title" => (string) $f["title"]],
            ];
        }, $list["assigned"]),
        "sent" => array_map(function ($f) {
            return [
                "id" => $f["id"],
                "sender_name" => (string) $f["sender_name"],
                "is_sender" => (bool) $f["is_sender"],
                "due_date" => v1NullableString($f["due_date"]),
                "status" => $f["status"],
                "answered" => $f["answered"],
                "total" => $f["total"],
                "sent_at" => (string) $f["sent_at"],
                "untrusted_content" => ["title" => (string) $f["title"]],
            ];
        }, $list["sent"]),
        "pending" => $list["pending"],
        "can_send" => (bool) $list["can_send"],
    ]);
}

$form = teamFormFor($pdo, $p, $id);
if (!$form) {
    v1Error(404, "not_found", "No team form with that id among the ones sent to you or that you may see.");
}

$out = [
    "id" => $form["id"],
    "status" => $form["status"],
    "due_date" => v1NullableString($form["due_date"]),
    "sent_at" => (string) $form["sent_at"],
    "sender" => ["id" => $form["sender"]["id"], "name" => (string) $form["sender"]["name"]],
    "is_sender" => $form["is_sender"],
    "sees_answers" => $form["sees_answers"],
    "target_roles" => array_column($form["target_roles"], "role"),
    "questions" => array_map(function ($q) {
        return [
            "id" => $q["id"],
            "type" => $q["type"],
            "required" => $q["required"],
            "untrusted_content" => ["text" => (string) $q["text"], "options" => $q["options"]],
        ];
    }, $form["questions"]),
    "mine" => $form["mine"] ? [
        "status" => $form["mine"]["status"],
        "submitted_at" => v1NullableString($form["mine"]["submitted_at"]),
        "overdue" => (bool) $form["mine"]["overdue"],
        "answers" => v1TeamFormAnswers($form["mine"]["answers"]),
    ] : null,
    "untrusted_content" => ["title" => (string) $form["title"], "description" => (string) $form["description"]],
];

if ($form["sees_answers"]) {
    $out["progress"] = $form["progress"];
    $out["recipients"] = array_map(function ($r) {
        return [
            "id" => $r["id"],
            "name" => (string) $r["name"],
            "role" => (string) $r["role"],
            "status" => $r["status"],
            "submitted_at" => v1NullableString($r["submitted_at"]),
            "overdue" => (bool) $r["overdue"],
            "answers" => v1TeamFormAnswers($r["answers"]),
        ];
    }, $form["recipients"]);
}

v1Reply(200, $out);
