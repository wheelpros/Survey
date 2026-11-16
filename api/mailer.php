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
| Every email the portal sends - these, and the password resets in
| forgot-password.php and sources-lock.php - goes through
| configurePortalSmtp(): the account and its password come only from the
| SMTP_* environment variables on the PHP app, never from the code.
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

/* Links in the emails point here: this install's own address, from the
   environment. Never worked out from the request - its Host header is whatever
   the sender says, and a reset link built from that goes to the sender's site. */
define("APP_URL", rtrim(mailerSetting("APP_URL", ""), "/"));

function mailerSetting(string $name, string $fallback): string
{
    $value = getenv($name);
    return ($value === false || $value === "") ? $fallback : $value;
}

/**
 * Points $mail at the portal's mailbox, from the environment only - set
 * APP_URL, SMTP_HOST, SMTP_PORT, SMTP_USER and SMTP_PASS on the PHP app in
 * Coolify (runtime, not build time; docker/portal.env.example lists them).
 * There is no password in this file: one in git is one anybody with the
 * repository or its history has.
 *
 * Returns false, and logs which setting is missing, when one isn't set - every
 * caller then reports "could not send" as it would for a mail server that is
 * down. Used by every email the portal sends: sendPortalEmail() below,
 * forgot-password.php and sources-lock.php. All of them carry a link, so with
 * no APP_URL they would all arrive broken.
 */
function configurePortalSmtp(PHPMailer $mail): bool
{
    if (APP_URL === "") {
        error_log("mailer: APP_URL is not set - no email can be sent");
        return false;
    }

    $username = mailerSetting("SMTP_USER", "");
    if ($username === "") {
        error_log("mailer: SMTP_USER is not set - no email can be sent");
        return false;
    }

    $password = getenv("SMTP_PASS");
    if ($password === false || $password === "") {
        error_log("mailer: SMTP_PASS is not set - no email can be sent");
        return false;
    }

    $mail->isSMTP();
    $mail->Host = mailerSetting("SMTP_HOST", "smtp.hostinger.com");
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) mailerSetting("SMTP_PORT", "587");
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Timeout = 10;

    return true;
}

/**
 * The text-only version of an email. A link is written out in full, since a
 * button with its address stripped leaves those readers nothing to follow.
 */
function portalEmailPlainText(string $html): string
{
    $text = preg_replace_callback(
        "/<a\b[^>]*\bhref=(['\"])(.*?)\\1[^>]*>(.*?)<\/a>/is",
        function ($link) {
            return trim(strip_tags($link[3])) . ": " . $link[2];
        },
        $html
    );

    // A row's label and value are two cells, and would otherwise run together.
    $text = preg_replace("/<\/td>\s*<td\b/i", ": <td", $text);
    $text = preg_replace("/<(br\s*\/?|\/p|\/h2|\/tr)>/i", "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, "UTF-8");

    // The markup's indentation and blank lines are not part of the message.
    $lines = array_filter(array_map("trim", explode("\n", $text)), "strlen");

    return implode("\n", $lines);
}

/**
 * One email to each recipient, over a single SMTP connection.
 *
 * $recipients is a list of ["email" => ..., "name" => ...]. Each gets their
 * own message rather than sharing a To line, so no admin sees who else was
 * told. Works for admins and clients alike. Returns how many were sent.
 *
 * $attachments is a list of [filename, content, mime type], sent with every
 * message - a meeting's calendar invite (.ics), for one.
 *
 * $sender (["name", "email"]) is the staff member the email is on behalf of:
 * their name is shown beside the portal's, and replies go to them. The
 * message itself still leaves from the portal's mailbox - the mail server
 * refuses, or spam filters bin, a From address it doesn't own.
 */
function sendPortalEmail(array $recipients, string $subject, string $html, array $attachments = [], array $sender = []): int
{
    if (!$recipients) {
        return 0;
    }

    $sent = 0;
    $mail = new PHPMailer(true);

    try {
        if (!configurePortalSmtp($mail)) {
            return 0;
        }

        // Several recipients share one connection instead of a login each.
        $mail->SMTPKeepAlive = true;

        $senderName = mb_substr(trim(preg_replace('/[\r\n]+/', " ", (string) ($sender["name"] ?? ""))), 0, 100);
        $senderEmail = trim((string) ($sender["email"] ?? ""));
        if ($senderEmail !== "" && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->setFrom($mail->Username, $senderName !== "" ? $senderName . " (" . portalName() . ")" : portalName());
            $mail->addReplyTo($senderEmail, $senderName);
        } else {
            $mail->setFrom($mail->Username, portalName());
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = portalEmailPlainText($html);

        foreach ($attachments as [$fileName, $content, $mime]) {
            $mail->addStringAttachment((string) $content, (string) $fileName, PHPMailer::ENCODING_BASE64, (string) $mime);
        }
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

    // No button when $button is empty: a guest has no portal page to open.
    $buttonHtml = "";
    if ($button !== "") {
        // A full URL (a booking link) is used as it is; a page is under APP_URL.
        $url = $e(preg_match('#^https?://#', $page) ? $page : APP_URL . "/" . ltrim($page, "/"));
        $buttonHtml = "<a href='{$url}' style='display:inline-block;background:#1b1e3a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 20px;border-radius:10px'>{$e($button)}</a>";
    }

    return "
<div style='font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;padding:24px'>
  <div style='max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px'>
    <h2 style='margin:0 0 8px;font-size:20px;color:#101828'>{$e($heading)}</h2>
    <p style='margin:0 0 18px;font-size:14px;color:#667085;line-height:1.5'>{$e($intro)}</p>
    <table style='border-collapse:collapse;margin-bottom:22px'>{$rowsHtml}</table>
    {$buttonHtml}
  </div>
  <p style='max-width:520px;margin:14px auto 0;font-size:12px;color:#98a2b3;text-align:center'>
    Sent by {$e(portalName())}{$e($button !== "" ? " because this is waiting on you." : ".")}
  </p>
</div>";
}

/*
| ── The two alerts ───────────────────────────────────────────────────────────
|
| Each one mirrors a notification the caller has just written, to the same
| admins (see meetingAdminIdsForUser() and reviewerAdminIds() in notify.php).
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
function emailReviewersAboutForm(PDO $pdo, string $formTitle, int $userId, int $authorAdminId, bool $isNew, int $surveyId = 0): int
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
        // Straight into this form's review, as the matching notification does.
        $surveyId ? "admin.html?review=" . $surveyId : "admin.html",
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
 * admins assigned to that client who can open the calendar.
 */
function emailAdminsAboutMeetingRequest(PDO $pdo, int $userId, string $clientName, string $topic, string $date, string $time, string $notes, int $appointmentId = 0): int
{
    $recipients = adminRecipients($pdo, meetingAdminIdsForUser($pdo, $userId));

    if (!$recipients) {
        return 0;
    }

    $client = clientLabel($pdo, $userId);

    $html = adminEmailHtml(
        "New meeting request",
        "A client has asked for a meeting. Confirm or decline it on the Appointments page.",
        [
            "Client" => $client,
            "From"   => $clientName !== $client ? $clientName : "",
            "Topic"  => $topic,
            "When"   => $date . " at " . $time,
            "Notes"  => $notes,
        ],
        // Straight to this request, as the matching notification does.
        $appointmentId ? "admin-appointments.html?appointment=" . $appointmentId : "admin-appointments.html",
        "Open the request"
    );

    return sendPortalEmail($recipients, "Meeting request from " . ($client ?: $clientName) . ": " . $topic, $html);
}

/**
 * An admin has asked a client for a meeting. $clients are the rows the
 * request went to (id, name, email, approved); an account still waiting on
 * approval cannot sign in to answer, so it is left out.
 */
function emailClientsAboutMeetingRequest(array $clients, string $adminName, string $topic, string $date, string $time, string $notes, array $sender = []): int
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
        "Your account team would like to meet. Accept or decline it in your portal.",
        [
            "From"  => $adminName,
            "Topic" => $topic,
            "When"  => $date . " at " . $time,
            "Notes" => $notes,
        ],
        "dashboard.html",
        "Answer the request"
    );

    return sendPortalEmail($recipients, "Meeting request: " . $topic, $html, [], $sender);
}

/**
 * A meeting's answer reached the client: the team confirmed or declined what
 * the client asked for, or cancelled a meeting (or a request of its own). Goes
 * to everyone on the client's account who can sign in - the portal shows it
 * only to whoever happens to look, and a meeting someone is planning their
 * day around should not depend on that.
 *
 * $outcome is "confirmed", "declined" or "cancelled".
 */
function emailClientsAboutMeetingOutcome(array $people, string $adminName, string $outcome, string $topic, string $date, string $time, string $note = "", array $options = []): int
{
    // $options: "guest" => true for someone without a portal login (no
    // portal button or wording), "timezone" => the zone the time is in,
    // "attachments" => the calendar invite, "sender" => the host
    // (["name", "email"]) it is sent on behalf of.
    $guest = !empty($options["guest"]);
    $recipients = [];

    foreach ($people as $person) {
        if ((int) ($person["approved"] ?? 1) === 1 && trim((string) ($person["email"] ?? "")) !== "") {
            $recipients[] = ["email" => $person["email"], "name" => $person["name"] ?? ""];
        }
    }

    if (!$recipients) {
        return 0;
    }

    $invite = !empty($options["attachments"]) ? " The calendar invite is attached." : "";
    $copy = [
        "confirmed" => ["Your meeting is confirmed", ($guest ? "See you then." : "It is in your portal calendar.") . $invite, "Meeting confirmed: "],
        "booked"    => ["A meeting was booked with you", ($guest ? "It's in the diary." : "It is in your portal calendar.") . $invite, "Meeting booked: "],
        "declined"  => ["Your meeting request was declined",
            $guest ? "The time you picked doesn't work. Reply to this email to find another one." : "The time you asked for doesn't work. You can ask for another one in your portal.",
            "Meeting request declined: "],
        "cancelled" => ["A meeting was cancelled", ($guest ? "This meeting is off." : "This meeting is off. Your portal calendar shows it as cancelled.") . $invite, "Meeting cancelled: "],
    ][$outcome] ?? null;

    if (!$copy) {
        return 0;
    }

    $zone = trim((string) ($options["timezone"] ?? ""));
    $html = adminEmailHtml(
        $copy[0],
        $copy[1],
        [
            "Topic" => $topic,
            "When"  => $date . " at " . substr($time, 0, 5) . ($zone !== "" ? " (" . $zone . ")" : ""),
            "With"  => $adminName,
            "Note"  => $note,
        ],
        "dashboard.html",
        $guest ? "" : "Open your portal"
    );

    return sendPortalEmail($recipients, $copy[2] . $topic, $html, $options["attachments"] ?? [], $options["sender"] ?? []);
}

/**
 * The host's own copy of a meeting's outcome, with the same calendar invite
 * the client got, so it lands in the host's calendar too. $host is
 * ["name", "email"]; $who names the client or guest.
 *
 * $outcome is "confirmed", "booked", "cancelled", or the client's answer to
 * the host's request: "accepted" or "declined_by_client".
 */
function emailHostMeetingCopy(array $host, string $outcome, string $who, string $topic, string $date, string $time, string $zone = "", int $appointmentId = 0, array $attachments = []): int
{
    if (!filter_var(trim((string) ($host["email"] ?? "")), FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $invite = $attachments ? " The calendar invite is attached." : "";
    $copy = [
        "confirmed"          => ["Meeting confirmed", "You confirmed this meeting; they have been told." . $invite, "Confirmed: "],
        "booked"             => ["Meeting booked", "You booked this meeting; they have been told." . $invite, "Booked: "],
        "cancelled"          => ["Meeting cancelled", "This meeting is off; they have been told." . $invite, "Cancelled: "],
        "accepted"           => ["Your meeting request was accepted", "The client accepted your meeting." . $invite, "Accepted: "],
        "declined_by_client" => ["Your meeting request was declined", "The client declined this time. You can suggest another one.", "Declined: "],
    ][$outcome] ?? null;

    if (!$copy) {
        return 0;
    }

    $html = adminEmailHtml(
        $copy[0],
        $copy[1],
        [
            "With"  => $who,
            "Topic" => $topic,
            "When"  => $date . " at " . substr($time, 0, 5) . ($zone !== "" ? " (" . $zone . ")" : ""),
        ],
        $appointmentId ? "admin-appointments.html?appointment=" . $appointmentId : "admin-appointments.html",
        "Open Appointments"
    );

    return sendPortalEmail([["email" => $host["email"], "name" => $host["name"] ?? ""]], $copy[2] . $topic . " with " . $who, $html, $attachments);
}

/**
 * A booking request: a link to pick one of the host's open times. Each
 * person gets their own link (one token each), so this is one email per call.
 */
function emailBookingInvite(array $person, string $hostName, string $topic, string $notes, string $link, string $lastDay, int $minutes, string $zone, array $sender = []): int
{
    $html = adminEmailHtml(
        "Pick a time to meet",
        $hostName . " would like to meet. Choose any time that suits you; you'll get a calendar invite once it's confirmed.",
        [
            "Topic"   => $topic,
            "Length"  => $minutes . " minutes",
            "Pick by" => $lastDay,
            "Times in" => $zone,
            "Notes"   => $notes,
        ],
        $link,
        "Choose a time"
    );

    return sendPortalEmail([$person], "Pick a time: " . $topic, $html, [], $sender);
}

/**
 * Someone picked a time from a booking link: the host (and the owner, who
 * sees every client) are asked to confirm or reject it.
 */
function emailHostAboutPickedTime(PDO $pdo, array $adminIds, string $who, string $topic, string $date, string $time, string $zone, string $message, int $appointmentId): int
{
    $recipients = adminRecipients($pdo, $adminIds);

    if (!$recipients) {
        return 0;
    }

    $html = adminEmailHtml(
        "A time was picked",
        "Confirm it and they get a calendar invite, or reject it and they're told.",
        [
            "Who"     => $who,
            "Topic"   => $topic,
            "When"    => $date . " at " . substr($time, 0, 5) . " (" . $zone . ")",
            "Message" => $message,
        ],
        "admin-appointments.html?appointment=" . $appointmentId,
        "Confirm or reject"
    );

    return sendPortalEmail($recipients, "Time picked: " . $topic, $html);
}
