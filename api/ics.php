<?php

/*
|--------------------------------------------------------------------------
| Calendar invites (.ics) for meetings
|--------------------------------------------------------------------------
|
| Attached to the emails that confirm or cancel a meeting, and downloaded from
| the Appointments page, so the meeting lands in Google Calendar, Outlook or
| Apple Calendar. RFC 5545, the subset every one of those reads.
|
| The UID is the same for a meeting's whole life, so the cancellation updates
| the event the confirmation created instead of adding a second one.
|
| Nothing here names the operator: the product id and UID host come from
| APP_URL, the organiser is the staff member who owns the meeting.
|
*/

require_once __DIR__ . "/mailer.php";

const MEETING_DEFAULT_MINUTES = 30;

/** Text as an iCalendar TEXT value: backslash, comma, semicolon, newline escaped. */
function icsText($value)
{
    $value = str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], (string) $value);
    return $value;
}

/** A content line folded at 75 octets, as the format requires. */
function icsLine($line)
{
    $out = "";
    while (strlen($line) > 75) {
        // Never split a multi-byte character.
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

/** A mailto value with the name as CN, quoted. */
function icsPerson($role, $name, $email, $extra = "")
{
    $name = str_replace(['"', "\r", "\n"], "", (string) $name);
    return $role . ($name !== "" ? ';CN="' . $name . '"' : "") . $extra . ":mailto:" . $email;
}

/**
 * The start and end of a meeting in UTC, from its wall-clock date and time in
 * $tz. Returns [DateTimeImmutable start, DateTimeImmutable end].
 */
function meetingUtcRange($date, $time, $tz, $minutes)
{
    try {
        $zone = new DateTimeZone($tz ?: "UTC");
    } catch (Throwable $e) {
        $zone = new DateTimeZone("UTC");
    }
    $start = new DateTimeImmutable($date . " " . substr((string) $time, 0, 5), $zone);
    $end = $start->modify("+" . max(5, (int) $minutes) . " minutes");
    $utc = new DateTimeZone("UTC");
    return [$start->setTimezone($utc), $end->setTimezone($utc)];
}

/**
 * The .ics for one meeting.
 *
 *   $meeting   id, date, time, duration_min, timezone, topic, notes, status
 *   $organizer [name, email] - the staff member who owns it
 *   $attendees list of [name, email]
 *   $method    "REQUEST" (it's on) or "CANCEL" (it's off)
 */
function meetingIcs(array $meeting, array $organizer, array $attendees, $method = "REQUEST")
{
    $cancel = $method === "CANCEL";
    [$start, $end] = meetingUtcRange(
        $meeting["date"],
        $meeting["time"],
        $meeting["timezone"] ?? "UTC",
        $meeting["duration_min"] ?? MEETING_DEFAULT_MINUTES
    );
    $host = parse_url(APP_URL, PHP_URL_HOST) ?: "portal.local";
    $fmt = "Ymd\THis\Z";
    $topic = trim((string) ($meeting["topic"] ?? "")) ?: "Meeting";

    $lines = [
        "BEGIN:VCALENDAR",
        "VERSION:2.0",
        "PRODID:-//" . $host . "//Appointments//EN",
        "CALSCALE:GREGORIAN",
        "METHOD:" . ($cancel ? "CANCEL" : "REQUEST"),
        "BEGIN:VEVENT",
        "UID:appointment-" . (int) $meeting["id"] . "@" . $host,
        "DTSTAMP:" . gmdate($fmt),
        "DTSTART:" . $start->format($fmt),
        "DTEND:" . $end->format($fmt),
        // The cancellation is a later revision of the same event.
        "SEQUENCE:" . ($cancel ? 1 : 0),
        "STATUS:" . ($cancel ? "CANCELLED" : "CONFIRMED"),
        "SUMMARY:" . icsText($topic),
    ];
    $notes = trim((string) ($meeting["notes"] ?? ""));
    if ($notes !== "") {
        $lines[] = "DESCRIPTION:" . icsText($notes);
    }
    if (!empty($organizer[1]) && filter_var($organizer[1], FILTER_VALIDATE_EMAIL)) {
        $lines[] = icsPerson("ORGANIZER", $organizer[0] ?? "", $organizer[1]);
    }
    foreach ($attendees as [$name, $email]) {
        if (filter_var((string) $email, FILTER_VALIDATE_EMAIL)) {
            $lines[] = icsPerson("ATTENDEE", $name, $email, ";ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE");
        }
    }
    $lines[] = "END:VEVENT";
    $lines[] = "END:VCALENDAR";

    return implode("", array_map("icsLine", $lines));
}

/** [filename, content, mime] for sendPortalEmail()'s $attachments. */
function meetingIcsAttachment(array $meeting, array $organizer, array $attendees, $method = "REQUEST")
{
    return [
        $method === "CANCEL" ? "cancelled-meeting.ics" : "meeting.ics",
        meetingIcs($meeting, $organizer, $attendees, $method),
        "text/calendar; charset=UTF-8; method=" . ($method === "CANCEL" ? "CANCEL" : "REQUEST"),
    ];
}
