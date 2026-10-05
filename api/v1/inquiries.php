<?php

/*
| Consultation inquiries - the public forms, as the office sees them.
|
|   GET inquiries.php?status=&cursor=&limit=   list_inquiries
|   GET inquiries.php?id=N                     get_inquiry_form
|
| Scope inquiries:read, which only the owner and account managers the owner
| gave inquiries access hold (admin-inquiries.php $hasAccess). Within it
| there is no further narrowing: like the browser page, everyone with access
| sees every inquiry. Leads are in leads.php.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);
v1RequireMethod("GET");
v1RequireStaff($p);
v1RequireScope($p, "inquiries:read");

ensureInquiryTables($pdo);

const INQUIRY_SQL = "
    SELECT i.id, i.title, i.intro_text, i.slug, i.status, i.reference, i.created_at,
           manager.name AS account_manager_name,
           (SELECT COUNT(*) FROM inquiry_responses r WHERE r.inquiry_id = i.id) AS lead_count,
           (SELECT MAX(r.submitted_at) FROM inquiry_responses r WHERE r.inquiry_id = i.id) AS latest_lead_at
    FROM inquiries i
    LEFT JOIN admins manager ON manager.id = i.account_manager_admin_id
";

function inquiryItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        // The readable part of the public link, inquiry.html?name=<name>.
        // Null until the browser page first gives an old inquiry one.
        "name" => v1NullableString($r["slug"] ?? null),
        // active: open for answers. inactive: closed.
        "status" => (string) ($r["status"] ?? "active"),
        "reference" => v1NullableString($r["reference"] ?? null),
        "account_manager_name" => v1NullableString($r["account_manager_name"]),
        "lead_count" => (int) $r["lead_count"],
        "latest_lead_at" => v1NullableString($r["latest_lead_at"]),
        "created_at" => (string) $r["created_at"],
    ];
}

$id = v1IntParam("id");

if ($id !== null) {
    $stmt = $pdo->prepare(INQUIRY_SQL . " WHERE i.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $inquiry = $stmt->fetch();

    if (!$inquiry) {
        v1Error(404, "not_found", "No inquiry with that id.");
    }

    $f = $pdo->prepare("
        SELECT id, field_label, field_type, required
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $f->execute([$id]);

    $data = inquiryItem($inquiry);
    $data["intro_text"] = v1PlainText($inquiry["intro_text"] ?? "");
    $data["fields"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "label" => (string) $r["field_label"],
            "type" => (string) $r["field_type"],
            "required" => (int) $r["required"] === 1,
        ];
    }, $f->fetchAll());

    v1Reply(200, $data);
}

$limit = v1Limit();
$after = v1IdCursor();
$parts = [];

$status = v1EnumParam("status", ["active", "inactive"]);
if ($status === "inactive") {
    $parts[] = ["i.status = 'inactive'", []];
} elseif ($status === "active") {
    $parts[] = ["(i.status IS NULL OR i.status <> 'inactive')", []];
}
if ($after !== null) {
    $parts[] = ["i.id < ?", [$after]];
}

[$where, $params] = v1Where($parts);
$stmt = $pdo->prepare(INQUIRY_SQL . $where . " ORDER BY i.id DESC LIMIT " . ($limit + 1));
$stmt->execute($params);

v1Reply(200, v1Page($stmt->fetchAll(), $limit, function ($r) {
    return [(int) $r["id"]];
}, "inquiryItem"));
