<?php

/*
| Form responses - what clients answered.
|
|   GET responses.php?form=&client=&cursor=&limit=   list_form_responses
|   GET responses.php?id=N                           get_form_response
|
| Scope forms:read. Same rule as admin-responses.php and response-details.php:
| the owner sees every response, everyone else only those from clients
| assigned to them. The answers come back structured, which is what the Excel
| export was for. Uploaded files are listed by name only - the files
| themselves stay behind the portal.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "forms:read");

ensureUserProfileColumns($pdo);

$scope = v1ClientScopeSql($p, "sr.user_id");

const RESPONSE_SQL = "
    SELECT sr.id, sr.survey_id, sr.survey_title, sr.user_id, sr.submitted_at,
           u.name AS user_name, u.email AS user_email, u.company_name
    FROM survey_responses sr
    JOIN users u ON u.id = sr.user_id
";

function responseItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "form_id" => (int) $r["survey_id"],
        "form_title" => (string) $r["survey_title"],
        "client" => v1ClientRef($r["user_id"], $r["user_name"], $r["company_name"], $r["user_email"]),
        "submitted_at" => (string) $r["submitted_at"],
    ];
}

$id = v1IntParam("id");

if ($id !== null) {
    [$where, $params] = v1Where([["sr.id = ?", [$id]], $scope]);
    $stmt = $pdo->prepare(RESPONSE_SQL . $where . " LIMIT 1");
    $stmt->execute($params);
    $response = $stmt->fetch();

    if (!$response) {
        v1Error(404, "not_found", "No response with that id among the responses you can see.");
    }

    $files = [];
    try {
        $f = $pdo->prepare("SELECT question_id, original_name FROM survey_uploaded_files WHERE response_id = ? ORDER BY id ASC");
        $f->execute([$id]);
        foreach ($f->fetchAll() as $row) {
            $files[(int) $row["question_id"]][] = (string) $row["original_name"];
        }
    } catch (PDOException $e) {
        // No uploads table on this deploy - no files to name.
    }

    $a = $pdo->prepare("
        SELECT question_id, question_label, answer
        FROM survey_answers
        WHERE response_id = ?
        ORDER BY id ASC
    ");
    $a->execute([$id]);

    $data = responseItem($response);
    $data["untrusted_content"] = [
        "answers" => array_map(function ($r) use ($files) {
            return [
                "question_id" => (int) $r["question_id"],
                // The label as it read when the client answered, kept even if
                // the form was edited later.
                "question" => (string) $r["question_label"],
                "answer" => (string) ($r["answer"] ?? ""),
                "files" => $files[(int) $r["question_id"]] ?? [],
            ];
        }, $a->fetchAll()),
    ];

    v1Reply(200, $data);
}

$limit = v1Limit();
$after = v1IdCursor();
$parts = [$scope];

$form = v1IntParam("form");
if ($form !== null) {
    $parts[] = ["sr.survey_id = ?", [$form]];
}
$client = v1IntParam("client");
if ($client !== null) {
    v1VisibleClient($pdo, $p, $client);
    $parts[] = ["sr.user_id = ?", [$client]];
}
if ($after !== null) {
    $parts[] = ["sr.id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare(RESPONSE_SQL . $where . " ORDER BY sr.id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, "responseItem"));
