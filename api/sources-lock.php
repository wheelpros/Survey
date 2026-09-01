<?php

/*
|--------------------------------------------------------------------------
| Files: the second password
|--------------------------------------------------------------------------
|
| Changing it is by emailed link only - there is no form that takes the current
| password and sets a new one, so a signed-in screen left unattended cannot be
| used to change the password and lock the real admin out.
|
| Signing in as an admin is not enough to reach Files. The page asks
| for a second password, set by that admin the first time they open it, and
| private to them - it is stored per admin row, so two admins never share it
| and one cannot open another's folders by knowing theirs.
|
| Unlocking issues a short-lived token. api/source-files.php and
| api/source-folders.php require it, which is what makes this a lock rather
| than a hidden page: without it the files come back to anyone holding an
| admin session token, whatever the browser was showing.
|
| Actions, all POST with a JSON body unless noted:
|
|   (GET)            has this admin set a password yet, and are they unlocked
|   set_password     first run only - choose the password, returns a token
|   unlock           check the password, returns a token
|   forgot           mail a reset link to the admin's own sign-in address
|   reset            finish that link, sets a new password
|
*/

require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

function lockReply($success, $message = "", $extra = [])
{
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
|
| Added here rather than in a migration you have to remember to run. Cheap
| after the first call - SHOW COLUMNS on a table this small is nothing.
|
*/

function ensureSourcesLockColumns(PDO $pdo)
{
    $existing = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);

    $columns = [
        "sources_password"        => "VARCHAR(255) NULL",
        "sources_reset_token"     => "VARCHAR(64) NULL",
        "sources_reset_expires"   => "DATETIME NULL",
        "sources_unlock_token"    => "VARCHAR(64) NULL",
        "sources_unlock_expires"  => "DATETIME NULL"
    ];

    foreach ($columns as $name => $type) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN {$name} {$type}");
        }
    }
}

/*
|--------------------------------------------------------------------------
| Shared with the file endpoints
|--------------------------------------------------------------------------
|
| source-files.php and source-folders.php require this file and call the
| function below. It answers one question: has this admin unlocked, in this
| browser session, recently enough.
|
| The unlock token lives in sessionStorage on the page, so closing the tab
| loses it and the password is asked for again on the next visit - which is
| the behaviour asked for. The expiry here is the backstop for a tab left
| open for days.
|
*/

define("SOURCES_UNLOCK_MINUTES", 120);

function sourcesUnlockToken()
{
    $headers = function_exists("getallheaders") ? getallheaders() : [];

    foreach ($headers as $name => $value) {
        if (strtolower($name) === "x-sources-token") {
            return trim($value);
        }
    }

    return trim($_SERVER["HTTP_X_SOURCES_TOKEN"] ?? "");
}

function sourcesUnlocked(PDO $pdo, $adminId)
{
    $token = sourcesUnlockToken();

    if ($token === "") {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE id = ?
          AND sources_unlock_token = ?
          AND sources_unlock_expires > NOW()
        LIMIT 1
    ");

    $stmt->execute([$adminId, $token]);

    return (bool) $stmt->fetch();
}

/*
| Called by the file endpoints instead of repeating the refusal in each.
| 423 is "locked" - the page uses it to know it should show the password box
| again rather than report a failure.
*/

function requireSourcesUnlock(PDO $pdo, $adminId)
{
    if (sourcesUnlocked($pdo, $adminId)) {
        return;
    }

    http_response_code(423);

    echo json_encode([
        "success" => false,
        "locked"  => true,
        "message" => "Enter your Files password to continue."
    ]);

    exit;
}

/*
| Everything below runs only when this file is the request target. When
| source-files.php requires it for the helpers above, nothing executes.
*/

if (basename($_SERVER["SCRIPT_FILENAME"] ?? "") !== basename(__FILE__)) {
    return;
}

ensureSourcesLockColumns($pdo);

/*
|--------------------------------------------------------------------------
| Who is calling
|--------------------------------------------------------------------------
|
| `reset` is the exception: it arrives from an emailed link, so there is no
| session behind it. The token in the URL is the credential there.
|
*/

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input["action"] ?? $_GET["action"] ?? "";

if ($action === "reset") {

    $token = trim((string) ($input["token"] ?? ""));
    $password = (string) ($input["password"] ?? "");
    $confirm = (string) ($input["confirm"] ?? "");

    if ($token === "" || $password === "") {
        lockReply(false, "Invalid request.");
    }

    if (strlen($password) < 8) {
        lockReply(false, "Password must be at least 8 characters.");
    }

    if ($password !== $confirm) {
        lockReply(false, "The two passwords do not match.");
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE sources_reset_token = ?
          AND sources_reset_expires > NOW()
        LIMIT 1
    ");

    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        lockReply(false, "This reset link is invalid or has expired.");
    }

    /*
    | The unlock token is cleared too. If someone else had the old password
    | and a tab open, this is what closes it.
    */
    $update = $pdo->prepare("
        UPDATE admins
        SET sources_password = ?,
            sources_reset_token = NULL,
            sources_reset_expires = NULL,
            sources_unlock_token = NULL,
            sources_unlock_expires = NULL
        WHERE id = ?
    ");

    $update->execute([
        password_hash($password, PASSWORD_DEFAULT),
        $admin["id"]
    ]);

    lockReply(true, "Password updated. You can open Files now.");
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$sessionToken = trim(str_replace("Bearer", "", $authHeader));

if ($sessionToken === "") {
    lockReply(false, "Unauthorized");
}

$stmt = $pdo->prepare("
    SELECT id, name, email, sources_password
    FROM admins
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$sessionToken]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    lockReply(false, "Unauthorized");
}

$hasPassword = !empty($admin["sources_password"]);

/*
| Issues a fresh unlock token and hands it back. Called after any successful
| set, unlock or change, so the admin is never asked twice in a row.
*/

function issueUnlockToken(PDO $pdo, $adminId)
{
    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        UPDATE admins
        SET sources_unlock_token = ?,
            sources_unlock_expires = DATE_ADD(NOW(), INTERVAL ? MINUTE)
        WHERE id = ?
    ");

    $stmt->execute([$token, SOURCES_UNLOCK_MINUTES, $adminId]);

    return $token;
}

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
|
| What the page asks on load: do I show "choose a password" or "enter your
| password", or is this session already unlocked.
|
*/

if ($_SERVER["REQUEST_METHOD"] === "GET" && $action === "") {

    lockReply(true, "", [
        "has_password" => $hasPassword,
        "unlocked"     => sourcesUnlocked($pdo, $admin["id"]),
        "email"        => $admin["email"]
    ]);
}

/*
|--------------------------------------------------------------------------
| First run
|--------------------------------------------------------------------------
|
| Only reachable while no password exists. Otherwise anyone borrowing a
| signed-in browser could overwrite the password without knowing it.
|
*/

if ($action === "set_password") {

    if ($hasPassword) {
        lockReply(false, "A password is already set. Enter it, or use the reset link.");
    }

    $password = (string) ($input["password"] ?? "");
    $confirm = (string) ($input["confirm"] ?? "");

    if (strlen($password) < 8) {
        lockReply(false, "Password must be at least 8 characters.");
    }

    if ($password !== $confirm) {
        lockReply(false, "The two passwords do not match.");
    }

    $stmt = $pdo->prepare("UPDATE admins SET sources_password = ? WHERE id = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $admin["id"]]);

    lockReply(true, "Password set.", [
        "token" => issueUnlockToken($pdo, $admin["id"])
    ]);
}

/*
|--------------------------------------------------------------------------
| Unlock
|--------------------------------------------------------------------------
*/

if ($action === "unlock") {

    if (!$hasPassword) {
        lockReply(false, "No password has been set yet.");
    }

    $password = (string) ($input["password"] ?? "");

    if (!password_verify($password, $admin["sources_password"])) {
        // Slows a script guessing through this endpoint without being
        // noticeable to someone typing their own password.
        usleep(400000);
        lockReply(false, "Wrong password.");
    }

    lockReply(true, "Unlocked.", [
        "token" => issueUnlockToken($pdo, $admin["id"])
    ]);
}

/*
|--------------------------------------------------------------------------
| Forgot
|--------------------------------------------------------------------------
|
| The link goes to the address this admin signs in with, and nowhere else -
| there is no address field to fill in. Someone at a borrowed screen can
| trigger the mail but it lands in the real admin's inbox.
|
*/

if ($action === "forgot") {

    require_once "../PHPMailer/src/Exception.php";
    require_once "../PHPMailer/src/PHPMailer.php";
    require_once "../PHPMailer/src/SMTP.php";

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        UPDATE admins
        SET sources_reset_token = ?,
            sources_reset_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
        WHERE id = ?
    ");

    $stmt->execute([$token, $admin["id"]]);

    $resetLink = "https://survey.websitezone.co.uk/sources-reset.html?token=" . $token;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = "smtp.hostinger.com";
        $mail->SMTPAuth = true;
        $mail->Username = "survey@wzonevr.com";
        $mail->Password = "Survey1@!t";
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom("survey@wzonevr.com", "Survey from WZone");
        $mail->addAddress($admin["email"], $admin["name"]);

        $mail->isHTML(true);
        $mail->Subject = "Reset your Files password";

        $mail->Body = "
            <h2>Files password</h2>
            <p>Click below to choose a new password for Files.</p>
            <p><a href='{$resetLink}'>Reset password</a></p>
            <p>This link expires in 30 minutes. If you did not ask for it,
            ignore this email - nothing changes until the link is used.</p>
        ";

        $mail->send();

    } catch (Throwable $e) {

        // The mailer's error text names the SMTP host and account, so it goes
        // to the log rather than back to the browser.
        error_log("sources-lock mail failed: " . $mail->ErrorInfo);

        lockReply(false, "Could not send the email. Please try again later.");
    }

    lockReply(true, "A reset link has been sent to " . $admin["email"] . ".");
}

lockReply(false, "Invalid action.");
