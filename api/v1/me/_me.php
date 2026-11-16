<?php

/*
|--------------------------------------------------------------------------
| api/v1/me - the portal as a client sees it
|--------------------------------------------------------------------------
|
| What the MCP server calls for a client's own AI assistant. Every endpoint
| here answers about the caller and nobody else: the client's id comes from
| the token, never from the request, exactly like the client pages
| (dashboard.html, content.html, user-projects.html...).
|
| A client's token only has scopes at all when the owner has switched client
| connections on (Connected apps; clientsMayUseMcp() in api/oauth/lib.php).
|
|   self:read   everything in this folder
|   self:write  POST notifications.php, and the client's changes through
|               ../changes.php (request a meeting, answer one, submit a form)
*/

require_once __DIR__ . "/../_bootstrap.php";

$p = v1Principal($pdo);

if ($p["kind"] !== "user") {
    v1Error(403, "clients_only", "This is the client's own view of the portal.");
}

ensureUserProfileColumns($pdo);

/** The caller's users row: id, name, email, company_name. */
function meRow(PDO $pdo, array $p)
{
    $stmt = $pdo->prepare("SELECT id, name, email, company_name, account_owner_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$p["id"]]);
    return withClientAccount($pdo, $stmt->fetch());
}

/*
| The meetings a client sees: their own, except a request W|ZONE sent that
| they declined - it drops off their calendar - while their own declined
| request stays, since they asked and are owed the answer. calendar.php
| get_user_calendar. Binds one ? (the client's id). Assumes `appointments a`.
*/
const ME_MEETINGS_SQL = "a.user_id = ? AND NOT (a.status = 'rejected' AND a.requested_by = 'admin')";

/*
| The content a client sees: live posts for their company, plus posts for
| every client. user-content.php. Binds three ? - contentNow(), the company,
| the company. Assumes `content c`.
*/
function meContentSql()
{
    return contentIsLiveSql("c") . " AND (c.client IS NULL OR c.client = '' OR (? <> '' AND c.client = ?))";
}

function meMeetingItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "date" => (string) $r["date"],
        "time" => substr((string) $r["time"], 0, 5),
        // pending | approved | rejected | cancelled
        "status" => (string) $r["status"],
        // 'admin': W|ZONE asked you; 'user': you asked W|ZONE.
        "requested_by" => (string) ($r["requested_by"] ?? "admin"),
        "with" => v1NullableString($r["admin_name"] ?? null),
        "untrusted_content" => [
            "topic" => (string) ($r["topic"] ?? $r["title"] ?? ""),
            "notes" => (string) ($r["notes"] ?? ""),
        ],
    ];
}

/**
 * A booking request to this account: the team asked them to pick one of the
 * host's open times. $withSlots adds those times (list_my_booking_requests);
 * the overview only counts them.
 */
function meBookingItem(PDO $pdo, array $r, $withSlots)
{
    $public = bookingRequestPublic($pdo, $r);
    $item = [
        "id" => (int) $r["id"],
        "with" => (string) $public["host"],
        "duration_min" => (int) $public["duration_min"],
        "timezone" => (string) $public["timezone"],
        "pick_by" => (string) $public["window_to"],
        "open_times" => count($public["slots"]),
        "untrusted_content" => ["topic" => (string) $public["topic"], "notes" => (string) $public["notes"]],
    ];
    if ($withSlots) {
        $item["slots"] = array_slice($public["slots"], 0, 300);
    }
    return $item;
}

function meContentItem(array $r, $captionMax)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        "type_label" => v1NullableString($r["type_label"]),
        "platform" => v1NullableString($r["platform"]),
        "live_at" => v1DateTime($r["live_at"]),
        "has_media" => !empty($r["media_path"]),
        "link" => v1NullableString($r["link"] ?? null),
        "untrusted_content" => ["caption" => v1PlainText($r["caption"] ?? "", $captionMax)],
    ];
}
