<?php

/*
| Inquiry leads - the answers people sent through a consultation form.
|
|   GET leads.php?inquiry=&since=&cursor=&limit=   list_inquiry_leads
|   GET leads.php?id=N                             get_inquiry_lead
|
| Scope inquiries:read - the same people who can open the leads page
| (admin-inquiry-responses.php). Without ?inquiry= the list runs across every
| inquiry, newest first, which is what triaging "everything since Monday"
| needs. Every answer is something a member of the public typed, so it comes
| back under untrusted_content.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "inquiries:read");

ensureInquiryTables($pdo);

/** Each lead's answers, labelled, in the form's own question order. */
function leadAnswers(PDO $pdo, array $responseIds)
{
    if (!$responseIds) {
        return [];
    }
    $in = implode(",", array_fill(0, count($responseIds), "?"));
    $stmt = $pdo->prepare("
        SELECT a.response_id, a.field_id, a.answer_text, f.field_label
        FROM inquiry_response_answers a
        LEFT JOIN inquiry_fields f ON f.id = a.field_id
        WHERE a.response_id IN ($in)
        ORDER BY f.sort_order ASC, a.field_id ASC
    ");
    $stmt->execute($responseIds);

    $by = [];
    foreach ($stmt->fetchAll() as $r) {
        $by[(int) $r["response_id"]][] = [
            "field_id" => (int) $r["field_id"],
            // A field removed since has no label left to show.
            "label" => (string) ($r["field_label"] ?? "(removed question)"),
            "answer" => (string) ($r["answer_text"] ?? ""),
        ];
    }
    return $by;
}

function leadItems(PDO $pdo, array $rows)
{
    $answers = leadAnswers($pdo, array_map(function ($r) {
        return (int) $r["id"];
    }, $rows));

    return array_map(function ($r) use ($answers) {
        return [
            "id" => (int) $r["id"],
            "inquiry" => ["id" => (int) $r["inquiry_id"], "title" => (string) ($r["title"] ?? "")],
            "submitted_at" => (string) $r["submitted_at"],
            // 'mcp' when an AI assistant sent it, 'web' or null for the form.
            "source" => v1NullableString($r["source"] ?? null),
            "untrusted_content" => ["answers" => $answers[(int) $r["id"]] ?? []],
        ];
    }, $rows);
}

// `r.*` rather than naming `source`: that column is added lazily.
const LEAD_SQL = "
    SELECT r.*, i.title
    FROM inquiry_responses r
    JOIN inquiries i ON i.id = r.inquiry_id
";

$id = v1IntParam("id");

if ($id !== null) {
    $stmt = $pdo->prepare(LEAD_SQL . " WHERE r.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        v1Error(404, "not_found", "No lead with that id.");
    }
    v1Reply(200, leadItems($pdo, [$row])[0]);
}

// Each lead carries its answers, so pages stay smaller than elsewhere.
$limit = v1Limit(20, 50);
$after = v1IdCursor();
$parts = [];

$inquiry = v1IntParam("inquiry");
if ($inquiry !== null) {
    $parts[] = ["r.inquiry_id = ?", [$inquiry]];
}
$since = v1DateParam("since");
if ($since !== null) {
    $parts[] = ["r.submitted_at >= ?", [$since . " 00:00:00"]];
}
if ($after !== null) {
    $parts[] = ["r.id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare(LEAD_SQL . $where . " ORDER BY r.id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

v1Reply(200, [
    "items" => leadItems($pdo, $rows),
    "next_cursor" => $more && $rows ? v1EncodeCursor([(int) end($rows)["id"]]) : null,
]);
