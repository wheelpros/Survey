<?php

/*
|--------------------------------------------------------------------------
| Staff availability and open times
|--------------------------------------------------------------------------
|
| Each Director or Account Manager sets their own bookable hours on the
| Appointments page. A booking request offers the sender's open times:
|
|   their weekly hours, in their own time zone
|   minus their days off
|   minus their meetings already confirmed or waiting (padded by the gap)
|   minus the minimum notice from now
|   up to the "bookable up to" horizon
|
| Everything is wall-clock time in the staff member's zone, the way the rest
| of the portal stores meetings (a date and a time, no offset); the zone is
| kept beside it so emails and invites can say which one.
|
*/

require_once __DIR__ . "/db.php";

const AVAILABILITY_DAYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
const AVAILABILITY_SLOTS = [15, 30, 45, 60];
const AVAILABILITY_BUFFERS = [0, 5, 10, 15, 30];
const AVAILABILITY_NOTICE = [0, 2, 12, 24, 48];
const AVAILABILITY_HORIZONS = [14, 30, 60, 90];
const AVAILABILITY_MAX_RANGES = 4;
const AVAILABILITY_MAX_OFF = 60;

/** The portal's own zone - Settings may set one; UTC otherwise. */
function portalTimezone(PDO $pdo)
{
    $tz = trim((string) getSiteSetting($pdo, "portal_timezone", ""));
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : "UTC";
}

function defaultWeeklyHours()
{
    $week = [];
    foreach (AVAILABILITY_DAYS as $day) {
        $week[$day] = in_array($day, ["sat", "sun"], true) ? [] : [["09:00", "17:00"]];
    }
    return $week;
}

function hhmmToMinutes($value)
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $value, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

function minutesToHhmm($minutes)
{
    return sprintf("%02d:%02d", intdiv($minutes, 60), $minutes % 60);
}

/**
 * A staff member's settings, with defaults for someone who never saved any:
 * ["timezone", "slot_minutes", "buffer_minutes", "notice_hours",
 *  "horizon_days", "weekly" => [day => [[from, to], ...]], "off" => [...]]
 */
function loadAvailability(PDO $pdo, $adminId)
{
    ensureBookingTables($pdo);

    $out = [
        "timezone" => portalTimezone($pdo),
        "slot_minutes" => 30,
        "buffer_minutes" => 0,
        "notice_hours" => 24,
        "horizon_days" => 30,
        "weekly" => defaultWeeklyHours(),
        "off" => [],
        "saved" => false,
    ];

    $stmt = $pdo->prepare("SELECT * FROM staff_availability WHERE admin_id = ?");
    $stmt->execute([(int) $adminId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $weekly = json_decode((string) $row["weekly_json"], true);
        $out = array_merge($out, [
            "timezone" => in_array($row["timezone"], timezone_identifiers_list(), true) ? $row["timezone"] : $out["timezone"],
            "slot_minutes" => (int) $row["slot_minutes"],
            "buffer_minutes" => (int) $row["buffer_minutes"],
            "notice_hours" => (int) $row["notice_hours"],
            "horizon_days" => (int) $row["horizon_days"],
            "weekly" => is_array($weekly) ? array_merge(defaultWeeklyHours(), $weekly) : defaultWeeklyHours(),
            "saved" => true,
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT id, date_from, date_to, label FROM staff_time_off
        WHERE admin_id = ? AND date_to >= ?
        ORDER BY date_from ASC
    ");
    $stmt->execute([(int) $adminId, date("Y-m-d", strtotime("-1 day"))]);
    $out["off"] = array_map(function ($r) {
        return ["id" => (int) $r["id"], "from" => $r["date_from"], "to" => $r["date_to"], "label" => (string) ($r["label"] ?? "")];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return $out;
}

/**
 * Checks and stores what the Availability dialog sends. Days off are replaced
 * as a whole list. Throws PortalWriteError with a sentence the page can show.
 */
function saveAvailability(PDO $pdo, $adminId, array $input)
{
    ensureBookingTables($pdo);

    $tz = (string) ($input["timezone"] ?? "");
    if (!in_array($tz, timezone_identifiers_list(), true)) {
        throw new PortalWriteError("Pick a time zone from the list.");
    }
    $pick = function ($key, array $allowed, $label) use ($input) {
        $value = (int) ($input[$key] ?? -1);
        if (!in_array($value, $allowed, true)) {
            throw new PortalWriteError("Pick one of the offered values for $label.");
        }
        return $value;
    };
    $slot = $pick("slot_minutes", AVAILABILITY_SLOTS, "meeting length");
    $buffer = $pick("buffer_minutes", AVAILABILITY_BUFFERS, "the gap between meetings");
    $notice = $pick("notice_hours", AVAILABILITY_NOTICE, "minimum notice");
    $horizon = $pick("horizon_days", AVAILABILITY_HORIZONS, "how far ahead");

    $weekly = [];
    $names = ["mon" => "Monday", "tue" => "Tuesday", "wed" => "Wednesday", "thu" => "Thursday", "fri" => "Friday", "sat" => "Saturday", "sun" => "Sunday"];
    foreach (AVAILABILITY_DAYS as $day) {
        $ranges = $input["weekly"][$day] ?? [];
        if (!is_array($ranges) || count($ranges) > AVAILABILITY_MAX_RANGES) {
            throw new PortalWriteError($names[$day] . " can have at most " . AVAILABILITY_MAX_RANGES . " sets of hours.");
        }
        $clean = [];
        foreach ($ranges as $range) {
            $from = hhmmToMinutes($range[0] ?? "");
            $to = hhmmToMinutes($range[1] ?? "");
            if ($from === null || $to === null) {
                throw new PortalWriteError("Use times like 09:00 for " . $names[$day] . ".");
            }
            if ($to - $from < $slot) {
                throw new PortalWriteError($names[$day] . ": each set of hours must end after it starts, with room for one meeting.");
            }
            $clean[] = [$from, $to];
        }
        usort($clean, function ($a, $b) { return $a[0] <=> $b[0]; });
        for ($i = 1; $i < count($clean); $i++) {
            if ($clean[$i][0] < $clean[$i - 1][1]) {
                throw new PortalWriteError($names[$day] . "'s hours overlap. Merge them or move one.");
            }
        }
        $weekly[$day] = array_map(function ($r) { return [minutesToHhmm($r[0]), minutesToHhmm($r[1])]; }, $clean);
    }

    $off = $input["off"] ?? [];
    if (!is_array($off) || count($off) > AVAILABILITY_MAX_OFF) {
        throw new PortalWriteError("That's more days off than this list can hold.");
    }
    $offRows = [];
    foreach ($off as $item) {
        $from = (string) ($item["from"] ?? "");
        $to = (string) ($item["to"] ?? $from);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw new PortalWriteError("Each day off needs a date, and its end can't be before its start.");
        }
        $offRows[] = [$from, $to, mb_substr(trim((string) ($item["label"] ?? "")), 0, 120)];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO staff_availability
                (admin_id, timezone, slot_minutes, buffer_minutes, notice_hours, horizon_days, weekly_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                timezone = VALUES(timezone), slot_minutes = VALUES(slot_minutes),
                buffer_minutes = VALUES(buffer_minutes), notice_hours = VALUES(notice_hours),
                horizon_days = VALUES(horizon_days), weekly_json = VALUES(weekly_json)
        ")->execute([(int) $adminId, $tz, $slot, $buffer, $notice, $horizon, json_encode($weekly)]);

        $pdo->prepare("DELETE FROM staff_time_off WHERE admin_id = ?")->execute([(int) $adminId]);
        $insert = $pdo->prepare("INSERT INTO staff_time_off (admin_id, date_from, date_to, label) VALUES (?, ?, ?, ?)");
        foreach ($offRows as [$from, $to, $label]) {
            $insert->execute([(int) $adminId, $from, $to, $label !== "" ? $label : null]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return loadAvailability($pdo, $adminId);
}

/** "Now" on the staff member's own clock. */
function availabilityNow(array $availability)
{
    return new DateTimeImmutable("now", new DateTimeZone($availability["timezone"]));
}

/**
 * The staff member's meetings between two days, by date: [date => [[start, end], ...]]
 * in minutes, padded by the gap. Confirmed meetings and ones still waiting on
 * an answer both hold their time. $exceptId leaves one meeting out.
 */
function busyMinutes(PDO $pdo, $adminId, $from, $to, $buffer, $exceptId = 0)
{
    $stmt = $pdo->prepare("
        SELECT id, date, time, duration_min
        FROM appointments
        WHERE admin_id = ? AND status IN ('approved', 'pending') AND date BETWEEN ? AND ? AND id <> ?
    ");
    $stmt->execute([(int) $adminId, $from, $to, (int) $exceptId]);

    $busy = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $start = hhmmToMinutes(substr((string) $row["time"], 0, 5)) ?? 0;
        $length = (int) ($row["duration_min"] ?: 30);
        $busy[$row["date"]][] = [$start - $buffer, $start + $length + $buffer];
    }
    return $busy;
}

/**
 * The open times between two days (inclusive) for a meeting of $minutes
 * (the staff member's meeting length when null): [["date" => .., "time" => ..], ...]
 */
function openSlots(PDO $pdo, $adminId, $from, $to, $minutes = null, ?array $availability = null)
{
    $a = $availability ?: loadAvailability($pdo, $adminId);
    $minutes = (int) ($minutes ?: $a["slot_minutes"]);
    $now = availabilityNow($a);
    $earliest = $now->modify("+" . (int) $a["notice_hours"] . " hours");
    $last = $now->modify("+" . (int) $a["horizon_days"] . " days")->format("Y-m-d");
    $first = max($from, $now->format("Y-m-d"));
    $to = min($to, $last);

    if ($first > $to) {
        return [];
    }

    $busy = busyMinutes($pdo, $adminId, $first, $to, (int) $a["buffer_minutes"]);
    $zone = new DateTimeZone($a["timezone"]);
    $slots = [];

    for ($day = new DateTimeImmutable($first, $zone); $day->format("Y-m-d") <= $to; $day = $day->modify("+1 day")) {
        $date = $day->format("Y-m-d");
        foreach ($a["off"] as $off) {
            if ($date >= $off["from"] && $date <= $off["to"]) {
                continue 2;
            }
        }
        $key = AVAILABILITY_DAYS[((int) $day->format("N")) - 1];
        foreach ($a["weekly"][$key] ?? [] as $range) {
            $start = hhmmToMinutes($range[0]);
            $end = hhmmToMinutes($range[1]);
            if ($start === null || $end === null) {
                continue;
            }
            // Steps of the meeting length from the start of the hours.
            for ($t = $start; $t + $minutes <= $end; $t += $minutes) {
                if ($day->setTime(intdiv($t, 60), $t % 60) < $earliest) {
                    continue;
                }
                foreach ($busy[$date] ?? [] as [$bs, $be]) {
                    if ($t < $be && $t + $minutes > $bs) {
                        continue 2;
                    }
                }
                $slots[] = ["date" => $date, "time" => minutesToHhmm($t)];
            }
        }
    }

    return $slots;
}

/** Whether $date $time is still one of the open times. */
function slotIsOpen(PDO $pdo, $adminId, $date, $time, $minutes)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || hhmmToMinutes(substr((string) $time, 0, 5)) === null) {
        return false;
    }
    foreach (openSlots($pdo, $adminId, $date, $date, $minutes) as $slot) {
        if ($slot["time"] === substr((string) $time, 0, 5)) {
            return true;
        }
    }
    return false;
}

/**
 * Why a hand-picked time may be a bad one: [] when it is fine, otherwise
 * sentences ("Outside your hours.", "Clashes with ..."). Adding a meeting
 * directly is allowed anyway; these are warnings.
 */
function meetingTimeWarnings(PDO $pdo, $adminId, $date, $time, $minutes)
{
    $a = loadAvailability($pdo, $adminId);
    $start = hhmmToMinutes(substr((string) $time, 0, 5));
    if ($start === null) {
        return [];
    }
    $warnings = [];

    $stmt = $pdo->prepare("
        SELECT topic, title, time, duration_min FROM appointments
        WHERE admin_id = ? AND status IN ('approved', 'pending') AND date = ?
    ");
    $stmt->execute([(int) $adminId, $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = hhmmToMinutes(substr((string) $row["time"], 0, 5)) ?? 0;
        $e = $s + (int) ($row["duration_min"] ?: 30);
        if ($start < $e && $start + $minutes > $s) {
            $warnings[] = "It clashes with \"" . ($row["topic"] ?: $row["title"] ?: "a meeting") . "\" at " . minutesToHhmm($s) . ".";
        }
    }

    $inside = false;
    $off = false;
    foreach ($a["off"] as $o) {
        if ($date >= $o["from"] && $date <= $o["to"]) {
            $off = true;
        }
    }
    $key = AVAILABILITY_DAYS[((int) (new DateTimeImmutable($date))->format("N")) - 1];
    foreach ($a["weekly"][$key] ?? [] as $range) {
        if ($start >= hhmmToMinutes($range[0]) && $start + $minutes <= hhmmToMinutes($range[1])) {
            $inside = true;
        }
    }
    if ($off) {
        $warnings[] = "That's one of your days off.";
    } elseif (!$inside) {
        $warnings[] = "It's outside your hours.";
    }

    return $warnings;
}
