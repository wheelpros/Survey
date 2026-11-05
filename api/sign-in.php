<?php

/*
|--------------------------------------------------------------------------
| One sign-in for staff and clients
|--------------------------------------------------------------------------
|
| Staff and clients are separate tables with separate logins, which is the
| portal's business and not the person's. The approval page (oauth-consent.html)
| asks for an email and a password and this works out which account they open.
|
|   POST { email, password }           -> what the matching login replies:
|                                         { token, admin } or { token, user }
|   POST { email, password, account }  -> the same, for "admin" or "user"
|
| The same email and password can open both a staff and a client account,
| because registering only checks the clients. Then, and only with the right
| password, the reply is { choose: [{ account, label, detail }] }.
|
| Issuing the session stays with admin-login.php and login.php, so there is one
| definition of a session and of what a deactivated or unapproved account is
| told. Wrong password and no such account read the same, and both check a
| password, so neither the reply nor its timing says who has an account.
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

header("Content-Type: application/json");
header("Cache-Control: no-store");

// A valid hash of nothing anyone would type, checked when there is no such account.
const SIGN_IN_NO_ACCOUNT_HASH = '$2y$10$ai5vIFL9WFEzUJ4izAvu8ePR7vFuoPX2ioAMEdB7/wDeUjpR1od4.';

function signInReply($status, array $body)
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    signInReply(405, ["success" => false, "message" => "POST only"]);
}
if (rateLimited($pdo, "sign-in", 20, 20, 300)) {
    signInReply(429, ["success" => false, "message" => "Too many attempts. Please wait a few minutes and try again."]);
}

$input = json_decode(file_get_contents("php://input"), true);
$email = strtolower(trim((string) ($input["email"] ?? "")));
$password = (string) ($input["password"] ?? "");
$account = (string) ($input["account"] ?? "");

if ($email === "" || $password === "") {
    signInReply(200, ["success" => false, "message" => "Email and password are required"]);
}

ensureAdminColumns($pdo);
ensureUserProfileColumns($pdo);

$stmt = $pdo->prepare("SELECT password, active FROM admins WHERE LOWER(email) = ? LIMIT 1");
$stmt->execute([$email]);
$admin = $stmt->fetch();

$stmt = $pdo->prepare("SELECT password, approved, company_name FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

$adminMatches = password_verify($password, $admin ? (string) $admin["password"] : SIGN_IN_NO_ACCOUNT_HASH) && $admin;
$userMatches = password_verify($password, $user ? (string) $user["password"] : SIGN_IN_NO_ACCOUNT_HASH) && $user;

if (!$adminMatches && !$userMatches) {
    signInReply(200, ["success" => false, "message" => "Invalid email or password"]);
}

// An account that can't be used yet isn't a choice worth offering. If it's the
// only match, its own login explains why below.
$adminUsable = $adminMatches && (int) $admin["active"] === 1;
$userUsable = $userMatches && (int) $user["approved"] === 1;

if ($account === "") {
    if ($adminUsable && $userUsable) {
        signInReply(200, [
            "success" => true,
            "choose" => [
                ["account" => "admin", "label" => "Staff account", "detail" => "The portal's team"],
                ["account" => "user", "label" => "Client account", "detail" => (string) ($user["company_name"] ?? "")],
            ],
        ]);
    }
    if ($adminUsable) {
        $account = "admin";
    } elseif ($userUsable) {
        $account = "user";
    } else {
        $account = $adminMatches ? "admin" : "user";
    }
}

if ($account === "admin" && $adminMatches) {
    require __DIR__ . "/admin-login.php";
    exit;
}
if ($account === "user" && $userMatches) {
    require __DIR__ . "/login.php";
    exit;
}

signInReply(200, ["success" => false, "message" => "Invalid email or password"]);
