<?php

/*
|--------------------------------------------------------------------------
| Read-only inquiry lookup - the AI-facing sibling of public-inquiry.php
|--------------------------------------------------------------------------
|
| public-inquiry.php mixes the public GET (show the form) with the POST
| (submit an answer) and legacy invite-token compatibility. That's fine for
| the form page itself, but it's not something external callers - MCP
| server included - should depend on directly, since that logic is free to
| change shape for reasons that have nothing to do with this.
|
| This file exposes exactly the same thing an anonymous visitor already
| sees, nothing more:
|
|   GET /api/inquiry-lookup.php?name=<slug>
|     -> { success, inquiry: { title, intro_text, status }, fields: [...] }
|
| Deliberately absent:
|   - No POST / answer submission (that stays in public-inquiry.php only).
|   - No "list all inquiries" mode. An inquiry is only reachable by
|     already knowing its exact name, same boundary the public form has -
|     adding enumeration would expose something that isn't already public.
|   - No internal ids, account_manager_admin_id, reference, or
|     created_by_admin_id/created_at. None of that reaches an anonymous
|     visitor today, so none of it reaches this endpoint either.
|
| Auth: none - this mirrors an already-public page. Rate-limited below as
| the defense-in-depth layer; nothing here can read or write anything a
| browser hitting inquiry.html couldn't already.
*/

require_once "db.php";

header("Content-Type: application/json");

require_once "rate-limit.php";

// Per visitor, per minute. The MCP server (Wzone-ai/) reaches this endpoint
// from one address on behalf of every AI client and already limits each of
// those itself - so it gets one shared bucket of its own, sized for all of
// them together. See api/rate-limit.php.
const LOOKUP_MAX_PER_IP = 30;
const LOOKUP_MAX_MCP = 300;

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Only GET is supported"]);
    exit;
}

if (rateLimited($pdo, "inquiry-lookup", LOOKUP_MAX_PER_IP, LOOKUP_MAX_MCP)) {
    http_response_code(429);
    echo json_encode(["success" => false, "message" => "Too many requests - please try again shortly"]);
    exit;
}

$name = trim((string) ($_GET["name"] ?? ""));

// Same shape a slug is generated in (lowercase, digits, single dashes) -
// anything else can't match a real row, so it's rejected before ever
// reaching a query.
if ($name === "" || !preg_match('/^[a-z0-9-]{1,200}$/', $name)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "A valid inquiry name is required"]);
    exit;
}

try {

    $stmt = $pdo->prepare("
        SELECT id, title, intro_text, status
        FROM inquiries
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $inquiry = $stmt->fetch();

    if (!$inquiry) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "No inquiry found with that name"]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, options, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$inquiry["id"]]);

    $fields = array_map(function ($field) {
        $options = trim((string) ($field["options"] ?? ""));

        return [
            "id" => (int) $field["id"],
            "label" => $field["field_label"],
            "type" => $field["field_type"],
            "required" => (bool) $field["required"],
            "options" => $options === "" ? [] : preg_split('/\r\n|\r|\n/', $options),
        ];
    }, $fieldsStmt->fetchAll());

    echo json_encode([
        "success" => true,
        "inquiry" => [
            "title" => $inquiry["title"],
            "intro_text" => $inquiry["intro_text"],
            // 'active' | 'inactive' - lets a caller distinguish "exists but
            // closed" from "not found", same distinction the public form
            // itself already shows a visitor.
            "status" => $inquiry["status"],
        ],
        "fields" => $fields,
    ]);
    exit;

} catch (PDOException $e) {
    // Never echo $e->getMessage() - it can carry schema/column detail.
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Unable to look up that inquiry right now"]);
    exit;
}
