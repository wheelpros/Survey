<?php

/*
|--------------------------------------------------------------------------
| Email: the alerts that go out alongside a notification
|--------------------------------------------------------------------------
|
| The sidebar badge only reaches someone who happens to open the portal. The
| events that are waiting on someone - a form to approve, a meeting to accept
| or decline - also go to that person's inbox, from here.
|
| Same SMTP account as the password-reset emails in forgot-password.php and
| sources-lock.php. The SMTP_* environment variables override it, so the
| password can move out of the code without touching this file again.
|
| Like notify.php, nothing in this file throws. A failed send is logged and
| the request that triggered it carries on: the form is saved and the meeting
| is requested whether or not the email made it.
|
*/

require_once __DIR__ . "/../PHPMailer/src/Exception.php";
require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;

/* Links in the emails point here - the address the reset emails already use. */
const APP_URL = "https://survey.websitezone.co.uk";

function mailerSetting(string $name, string $fallback): string
{
    $value = getenv($name);
    return ($value === false || $value === "") ? $fallback : $value;
}

/**
 * One email to each recipient, over a single SMTP connection.
 *
 * $recipients is a list of ["email" => ..., "name" => ...]. Each gets their
 * own message rather than sharing a To line, so no admin sees who else was
 * told. Works for admins and clients alike. Returns how many were sent.
 */
function sendPortalEmail(array $recipients, string $subject, string $html): int
{
    if (!$recipients) {
        return 0;
    }

    $sent = 0;
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = mailerSetting("SMTP_HOST", "smtp.hostinger.com");
        $mail->SMTPAuth = true;
        $mail->Username = mailerSetting("SMTP_USER", "survey@wzonevr.com");
        $mail->Password = mailerSetting("SMTP_PASS", "Survey1@!t");
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) mailerSetting("SMTP_PORT", "587");
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 10;

        // Several recipients share one connection instead of a login each.
        $mail->SMTPKeepAlive = true;

        $mail->setFrom($mail->Username, "W Zone Portal");
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(
            preg_replace("/<(br|\/p|\/h2|\/tr)>/i", "\n", $html)
        ), ENT_QUOTES, "UTF-8"));
    } catch (Throwable $e) {
        error_log("sendPortalEmail setup failed: " . $e->getMessage());
        return 0;
    }

    // Connected once, up front: with the mail server down, trying again for
    // every recipient would hold up the save that triggered this by several
    // seconds for nothing.
    try {
        if (!$mail->smtpConnect()) {
            error_log("sendPortalEmail: could not connect to the SMTP server");
            return 0;
        }
    } catch (Throwable $e) {
        error_log("sendPortalEmail: could not connect: " . $e->getMessage());
        return 0;
    }

    foreach ($recipients as $recipient) {
        $email = trim((string) ($recipient["email"] ?? ""));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        try {
            $mail->clearAddresses();
            $mail->addAddress($email, (string) ($recipient["name"] ?? ""));
            $mail->send();
            $sent++;
        } catch (Throwable $e) {
            // The mailer's error text names the SMTP host and account, so it
            // goes to the log rather than anywhere a browser could see it.
            error_log("sendPortalEmail to $email failed: " . $mail->ErrorInfo);
        }
    }

    try {
        $mail->smtpClose();
    } catch (Throwable $e) {
        // Already closed or never opened.
    }

    return $sent;
}

/**
 * Name and email of each active admin in $ids, minus $exceptAdminId.
 */
function adminRecipients(PDO $pdo, array $ids, int $exceptAdminId = 0): array
{
    $ids = array_values(array_unique(array_filter(
        array_map("intval", $ids),
        function ($id) use ($exceptAdminId) {
            return $id > 0 && $id !== $exceptAdminId;
        }
    )));

    if (!$ids) {
        return [];
    }

    try {
        $placeholders = implode(",", array_fill(0, count($ids), "?"));
        $stmt = $pdo->prepare("
            SELECT name, email FROM admins
            WHERE active = 1 AND id IN ($placeholders)
        ");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The email body every alert shares: a heading, a few label/value rows, and
 * one button to the page where the admin acts on it. Every value is escaped
 * here - titles, names and notes all come from people typing.
 */
function adminEmailHtml(string $heading, string $intro, array $rows, string $page, string $button): string
{
    $e = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    };

    $rowsHtml = "";
    foreach ($rows as $label => $value) {
        if ($value === null || trim((string) $value) === "") {
            continue;
        }
        $rowsHtml .= "<tr>"
            . "<td style='padding:6px 16px 6px 0;color:#667085;font-size:13px;vertical-align:top;white-space:nowrap'>" . $e($label) . "</td>"
            . "<td dir='auto' style='padding:6px 0;color:#101828;font-size:14px;font-weight:bold'>" . nl2br($e($value)) . "</td>"
            . "</tr>";
    }

    $url = $e(APP_URL . "/" . ltrim($page, "/"));

    return "
<div style='font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;padding:24px'>
  <div style='max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px'>
    <h2 style='margin:0 0 8px;font-size:20px;color:#101828'>{$e($heading)}</h2>
    <p style='margin:0 0 18px;font-size:14px;color:#667085;line-height:1.5'>{$e($intro)}</p>
    <table style='border-collapse:collapse;margin-bottom:22px'>{$rowsHtml}</table>
    <a href='{$url}' style='display:inline-block;background:#1b1e3a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 20px;border-radius:10px'>{$e($button)}</a>
  </div>
  <p style='max-width:520px;margin:14px auto 0;font-size:12px;color:#98a2b3;text-align:center'>
    Sent by the W Zone portal because this is waiting on you.
  </p>
</div>";
}

/*
| ── The two alerts ───────────────────────────────────────────────────────────
|
| Each one mirrors a notification the caller has just written, to the same
| admins (see adminIdsForUser() and reviewerAdminIds() in notify.php).
*/

require_once __DIR__ . "/notify.php";

/** A client's company, falling back to their name for an account without one. */
function clientLabel(PDO $pdo, int $userId): string
{
    try {
        $stmt = $pdo->prepare("SELECT name, company_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return "";
    }

    if (!$user) {
        return "";
    }

    return trim((string) ($user["company_name"] ?? "")) ?: (string) $user["name"];
}

/**
 * A form is waiting on approval. Goes to the account managers and the owner,
 * except whoever wrote it.
 */
function emailReviewersAboutForm(PDO $pdo, string $formTitle, int $userId, int $authorAdminId, bool $isNew): int
{
    $recipients = adminRecipients($pdo, reviewerAdminIds($pdo), $authorAdminId);

    if (!$recipients) {
        return 0;
    }

    $author = "";
    try {
        $stmt = $pdo->prepare("SELECT name FROM admins WHERE id = ?");
        $stmt->execute([$authorAdminId]);
        $author = (string) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // The email still makes sense without it.
    }

    $html = adminEmailHtml(
        $isNew ? "New form awaiting approval" : "Updated form awaiting approval",
        "The client will not see this form until it is approved.",
        [
            "Form"   => $formTitle,
            "Client" => clientLabel($pdo, $userId),
            "By"     => $author,
        ],
        "admin.html",
        "Review the form"
    );

    return sendPortalEmail(
        $recipients,
        ($isNew ? "Form awaiting approval: " : "Updated form awaiting approval: ") . $formTitle,
        $html
    );
}

/**
 * A client has asked the admin team for a meeting. Goes to the owner and the
 * admins assigned to that client.
 */
function emailAdminsAboutMeetingRequest(PDO $pdo, int $userId, string $clientName, string $topic, string $date, string $time, string $notes): int
{
    $recipients = adminRecipients($pdo, adminIdsForUser($pdo, $userId));

    if (!$recipients) {
        return 0;
    }

    $client = clientLabel($pdo, $userId);

    $html = adminEmailHtml(
        "New meeting request",
        "A client has asked for a meeting. Accept or decline it on the calendar.",
        [
            "Client" => $client,
            "From"   => $clientName !== $client ? $clientName : "",
            "Topic"  => $topic,
            "When"   => $date . " at " . $time,
            "Notes"  => $notes,
        ],
        "admin-calendar.html",
        "Open the calendar"
    );

    return sendPortalEmail($recipients, "Meeting request from " . ($client ?: $clientName) . ": " . $topic, $html);
}

/**
 * An admin has asked a client for a meeting. $clients are the rows the
 * request went to (id, name, email, approved); an account still waiting on
 * approval cannot sign in to answer, so it is left out.
 */
function emailClientsAboutMeetingRequest(array $clients, string $adminName, string $topic, string $date, string $time, string $notes): int
{
    $recipients = [];

    foreach ($clients as $client) {
        if ((int) ($client["approved"] ?? 0) === 1) {
            $recipients[] = ["email" => $client["email"] ?? "", "name" => $client["name"] ?? ""];
        }
    }

    if (!$recipients) {
        return 0;
    }

    $html = adminEmailHtml(
        "New meeting request",
        "The W Zone team would like to meet. Accept or decline it in your portal.",
        [
            "From"  => $adminName,
            "Topic" => $topic,
            "When"  => $date . " at " . $time,
            "Notes" => $notes,
        ],
        "user-appointments.html",
        "Answer the request"
    );

    return sendPortalEmail($recipients, "Meeting request: " . $topic, $html);
}
