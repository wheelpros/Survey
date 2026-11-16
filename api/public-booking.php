<?php

/*
|--------------------------------------------------------------------------
| Picking a meeting time - book.html
|--------------------------------------------------------------------------
|
| Public: the token from the emailed link is the only credential, the same
| as join-team.html. Works for a guest with no account and for a client who
| isn't signed in.
|
|   GET  ?t=                                   the request, its state, open times
|   POST {t, date, time, name, email, message} takes one of the times
|
| Never returns the host's email or who else the request went to.
|
*/

require_once "booking-writes.php";
require_once "rate-limit.php";

header("Content-Type: application/json; charset=UTF-8");
header("Referrer-Policy: no-referrer");
header("Cache-Control: no-store");

function bookingReply($success, $message = "", $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

ensureAppointmentTables($pdo);
ensureNotificationsTable($pdo);

if (rateLimited($pdo, "public-booking", 60, 60, 60)) {
    bookingReply(false, "Too many attempts. Please wait a minute and try again.", [], 429);
}

$method = $_SERVER["REQUEST_METHOD"];
$input = $method === "POST" ? (json_decode(file_get_contents("php://input"), true) ?: []) : [];
$found = findBookingLink($pdo, $method === "POST" ? ($input["t"] ?? "") : ($_GET["t"] ?? ""));

if (!$found) {
    bookingReply(false, "This link doesn't work. It may have been replaced by a newer one.", ["state" => "invalid"], 404);
}

[$link, $request] = [$found["link"], $found["request"]];

if ($method === "GET") {
    if (!$link["opened_at"]) {
        $pdo->prepare("UPDATE booking_request_links SET opened_at = NOW() WHERE id = ?")->execute([(int) $link["id"]]);
    }
    bookingReply(true, "", ["booking" => bookingRequestPublic($pdo, $request, $link)]);
}

if ($method !== "POST") {
    bookingReply(false, "Unsupported request method.", [], 405);
}

$isGuest = !$link["user_id"];
$name = trim((string) ($input["name"] ?? ""));
$email = trim((string) ($input["email"] ?? ""));

// A guest may correct their name and email; a client is who the link says.
if ($isGuest) {
    if ($name === "") {
        bookingReply(false, "Add your name.", [], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bookingReply(false, "Add a valid email so we can send the invite.", [], 422);
    }
} else {
    $name = (string) ($link["name"] ?? "");
    $email = (string) $link["email"];
}

try {
    pickBookingSlot($pdo, (int) $request["id"], (string) ($input["date"] ?? ""), (string) ($input["time"] ?? ""), [
        "account_id" => $link["user_id"] ? (int) $link["user_id"] : null,
        "name" => $name,
        "email" => $email,
        "message" => (string) ($input["message"] ?? ""),
        "link_id" => (int) $link["id"],
    ]);
} catch (PortalWriteError $e) {
    bookingReply(false, $e->getMessage(), [], $e->status);
}

$request = bookingRequestById($pdo, (int) $request["id"]);
bookingReply(true, "Request sent.", ["booking" => bookingRequestPublic($pdo, $request, $link)]);
