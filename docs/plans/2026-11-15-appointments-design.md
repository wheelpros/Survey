<!-- Status: all three parts are built on the appointments branch - the rename and meetings-only page, availability, and booking (direct add, booking links for clients and guests, book.html, .ics invites). -->

# Appointments: rename the staff Calendar, add direct booking, booking links and availability

## Context
Today the page at `admin-calendar.html` is called "Dynamic Content Calendar". It mixes three kinds of item: meetings (`appointments`), content posts and project phases. The only booking it offers is "propose one fixed date and time to a company". Directors and Account Managers want a page that does one job, meetings, and supports two ways of booking:

1. **Add directly**: the meeting is confirmed at once, lands on the calendar, and the client or guest gets an email with a calendar invite (.ics).
2. **Send a booking request**: the client (in the portal) or a guest (anyone with an email, outside the system) picks a free slot from the sender's own availability. Staff then **confirm** it (it becomes a confirmed meeting and the attendee gets an email with the .ics) or **reject** it (the attendee gets an email).

Each staff member sets their own weekly hours, days off, buffer, notice, booking horizon and timezone.

Decisions already made: the page shows meetings only, the client picks from open slots, availability is per staff member, and confirmations include a .ics invite. There is no per-meeting duration field: the length comes from the host's "meeting length" setting. Guest fields are name and email.

Constraint (memory): the portal is multi-company. No operator name, domain or recipient may be hardcoded in email copy, .ics files or the page. Use the portal branding settings and `APP_URL`.

## What exists and gets reused
- `api/meeting-writes.php`: `meetingScopedClients`, `meetingTargetsForCompany`, `requestMeetingFromClients`, `answerClientMeetingRequest` (approve/reject), `answerAdminMeetingRequest`.
- `api/calendar.php`: action router. `get_admin_calendar` (339), `respond_request` (246), `create_admin_request` (216).
- `api/mailer.php`: `sendPortalEmail` (117) and `adminEmailHtml` (219). PHPMailer already supports `addStringAttachment`.
- `api/notify.php`: `notify`, `notifyClientAccount`, `meetingAdminIdsForUser`. Note that `notify()` refuses to run inside a transaction.
- Guest token pattern from client invites (`api/client-team-writes.php:162-191, 371-407`, `api/accept-invite.php`): `random_bytes(32)`, store only the sha256, `expires_at`, locked single-use claim, `Referrer-Policy: no-referrer`, `rateLimited()` (`api/rate-limit.php:156`).
- `assets/confirm.js` `askConfirm()` replaces `alert()`. `assets/shell.css` `.acct-modal` dialog shell. `getSiteSetting` / `setSiteSetting` (`api/db.php:633,706`).
- Redirect-stub precedent: `user-appointments.html`.

## Data model (lazy `ensure*()` in `api/db.php` plus manual `sql/*.sql`, following existing practice)
**`appointments`: new columns.** All new columns are nullable, so existing rows keep working:
- `duration_min INT`, `timezone VARCHAR(64)`. Needed for the .ics end time and for clash checks. Old rows count as 30 minutes in the portal timezone.
- `guest_name VARCHAR(150)`, `guest_email VARCHAR(190)`. Set for guest meetings only.
- `source VARCHAR(12)`: `direct` | `booking` | `request` (client asked) | `proposal` (legacy fixed-time proposal).
- `booking_request_id INT`.
- `status` gains `cancelled`.
- `user_id` becomes `NULL`able, because guests have no account. Joins to `users` in `get_admin_calendar` and `api/v1/calendar.php` become `LEFT JOIN`s.

**`booking_requests`** (new): `id, admin_id (host), client VARCHAR(150) NULL (company), guest_name, guest_email, topic, notes, duration_min, timezone, window_from DATE, window_to DATE, status open|picked|closed|cancelled, appointment_id NULL, created_at, expires_at`. A request is "expired" when the date passes `window_to`; this is checked when read, so no cron job is needed.

**`booking_request_links`** (new): one row per person the email goes to. That is each client account and teammate at the company, or the single guest. Columns: `id, booking_request_id, user_id NULL (client account), name, email, token_hash CHAR(64) UNIQUE, opened_at NULL, used_at NULL`. Because every recipient has their own token, the page knows who picked, and the host can tell who opened the link. Once one recipient picks, the request is `picked` and every other link shows "Already booked by {name}".

**`staff_availability`** (new, one row per admin): `admin_id PK, timezone, slot_minutes (15/30/45/60), buffer_minutes, notice_hours, horizon_days, weekly_json` (for example `{"mon":[["09:00","17:00"]],...}`), `updated_at`. The defaults are Mon–Fri 09:00–17:00, 30 minutes, no buffer, 24 hours' notice, 30 days ahead, and the portal timezone (site setting `portal_timezone`, falling back to UTC).

**`staff_time_off`** (new): `id, admin_id, date_from, date_to, label`.

Times are stored as they are today (date plus time, no zone), in the host's timezone, and the row's `timezone` names it. The .ics converts to UTC with `DateTimeZone`.

## Backend
**New `api/availability.php`**
- `loadAvailability($pdo, $adminId)`
- `saveAvailability(...)`, which checks that ranges don't overlap, start comes before end, and the timezone is valid (`timezone_identifiers_list`).
- `openSlots($pdo, $adminId, $from, $to)`: weekly hours, minus time off, minus the host's appointments with status `approved` or `pending` (padded by the buffer), minus the notice window, capped at the horizon. Returns `[{date, time}]`.
- `slotIsFree(...)`, used again when a slot is picked.

**New `api/ics.php`**
- `meetingIcs(array $appt, array $host, array $attendee, string $method)` with methods `REQUEST` and `CANCEL`.
- The UID is `appointment-{id}@{APP_URL host}`, so a later cancellation updates the same calendar entry.

**`api/mailer.php`**
- `sendPortalEmail(..., array $attachments = [])`, where each attachment is `[name, content, mime]`.
- New emails, all built on `adminEmailHtml` and portal branding:
  - Booking request (client version: "Pick a time in the portal"; guest version: a link to `book.html?t=…`).
  - "A time was picked: confirm or reject" (to the host and the owner).
  - Confirmed (with the .ics).
  - Rejected.
  - Cancelled (with a CANCEL .ics).
- Existing approve/decline answers will also start sending an email, which closes a current gap.

**`api/meeting-writes.php`: new functions**
- `addMeetingDirectly($pdo, $admin, target, date, time, topic, notes)`. The target is either a company or a guest `{name, email}`. It inserts `approved` / `source=direct` with the host's duration and timezone, warns on a clash with availability (`409` unless `force`), then sends the notification and the email with the .ics.
- `createBookingRequest($pdo, $admin, target, topic, notes, window)`. Creates one request per company (not one per account; the first account to pick wins). Guests get a token, and an email or in-app notification goes out.
- `pickBookingSlot($pdo, $request, $date, $time, $picker)`. Inside a transaction it runs `SELECT … FOR UPDATE` on the request (must be `open`) and on the host's `staff_availability` row, re-checks `slotIsFree`, and inserts an `appointments` row with `pending` / `requested_by='user'` / `source=booking`. The request becomes `picked`. Notifications go out after commit. If the time was just taken, it returns `409 That time was just taken`.
- `answerClientMeetingRequest` is extended:
  - Confirm sends the .ics email.
  - Reject emails the attendee and closes the request. A guest row has no `user_id`, so it emails `guest_email` instead of calling `notifyClientAccount`.
- `cancelMeeting(...)` sends a CANCEL .ics. This is needed so a direct add can be undone; the page has no cancel today.

**`api/calendar.php`: new staff actions** (all behind `calendar.use`, and scoped so that guest meetings and requests are visible to their host and to anyone with `clients.view_all`):
- `get_availability`, `save_availability`
- `add_meeting`
- `create_booking_request`, `cancel_booking_request`, `resend_booking_request`
- `cancel_meeting`
- `get_admin_calendar` stops returning `content` and `phases`. It adds `booking_requests` and the current user's availability, which the page uses for shading.

**New client actions**: `get_booking_slots` and `pick_booking_slot`. These are for requests addressed to the client's company, used by `dashboard.html`.

**Booking page link: the same flow for clients and guests**
1. Staff send a booking request. `createBookingRequest` creates one `booking_request_links` row for each recipient, with a token from `bin2hex(random_bytes(32))`. Only `sha256(token)` is stored.
2. Each recipient gets their own email: "{host} invited you to book a meeting: {topic}", with a **Choose a time** button linking to `{APP_URL}/book.html?t={token}`. The button URL is built from `APP_URL` and the operator name comes from portal branding; nothing is hardcoded.
3. The link opens `book.html`, which needs no login (the token is the credential, exactly like `join-team.html`). It calls `GET api/public-booking.php?t=` and shows the matching state:
   - **Open**: host name, topic, duration and the open slots.
   - **Already booked / Expired / Cancelled / Link not valid**.
   - **Picked, waiting for confirmation**: shown when they come back after picking.
4. They pick a slot. Name and email are pre-filled from their link row; guests can correct them. There is an optional message. The page calls `POST api/public-booking.php` with `{t, date, time, name, email, message}`, which runs `pickBookingSlot`.
5. The page shows "Request sent. You'll get an email when {host} confirms." Staff see the pick under Needs your action. Confirming sends the .ics email; rejecting sends the rejection email.

Client accounts also see the request in `dashboard.html` (Approvals Hub, **Pick a time**). That uses the same slot picker through the client actions above, so a client who is already signed in can book without the email.

**New public `api/public-booking.php`** (no login; the token is the credential):
- Rate-limited with `rateLimited()`. Sends `Referrer-Policy: no-referrer`.
- The token must match `^[a-f0-9]{64}$`.
- `GET` returns only the host's display name, topic, duration, timezone, slots and state. It never returns the host's email or other recipients' details. It sets `opened_at`.
- `POST` re-checks the token, the state and the slot inside the transaction, then sets `used_at`.

**Kept as it is**: the old fixed-time proposal (`requestMeetingFromClients`, MCP `propose_meeting`) stays working so existing rows and the AI tools don't break. The page simply stops offering it.

## Frontend
**Rename**
- `admin-calendar.html` becomes **`admin-appointments.html`**. `admin-calendar.html` becomes a redirect stub that keeps the query string (`?appointment=`).
- Links are updated in:
  - `assets/admin-nav.js:74` (label "Appointments").
  - `api/meeting-writes.php:243,299`.
  - `api/mailer.php:336,345` ("…on the Appointments page").
  - `api/task-writes.php:972`.
- The permission **key `calendar.use` and the OAuth scopes stay unchanged**, because they are stored in role settings and in tokens. Only the labels change:
  - `api/permissions.php:43,80`: "Appointments" / "Use appointments and book meetings".
  - `api/oauth/lib.php` consent text.
  - Update `tests/api-v1/permissions.php:196`.

**New page layout** (chosen: option A, changed so the calendar is full width and **one "To handle" section sits under it**. Its tabs sit at the right of its header and act as filters: Needs your action (red count) / Waiting on others / Upcoming. Cards are three across on desktop, two on a tablet and stacked on a phone. On a phone the tabs move under the title, full width, with short names (To answer / Sent / Upcoming); see `demo/appointment-designs/a-calendar-rail.html`):
- **Header**: h1 "Appointments", the subtitle "Your meetings and booking requests", a secondary button **Availability**, and a primary button **New appointment**.
- **Main column, calendar**:
  - Day/Week/Month.
  - Week and Day become a time grid (hour rows, blocks sized by duration), with the host's working hours shaded and time off hatched.
  - Month keeps its chips.
  - Phone (≤700px), as in the demo:
    - Header: title and arrows/Today on one line, Day/Week/Month full width under them. The colour key scrolls sideways in one line.
    - Day is the default view: a strip of the week's days (dot = has meetings, amber = needs you) above one day of hours.
    - Week shows as a list of that week's meetings.
    - Month shows dots, and tapping a day opens it.
  - Only meeting colours remain: Confirmed, Awaiting your confirmation, Requested by client, Declined/Cancelled (muted). The phase and content styles and code (`dayItems` 1432–1493) go.
- **Sections under the calendar**:
  - **Needs your action**: picked slots and client requests waiting on you, with inline Confirm/Reject. This replaces the Approvals Hub.
  - **Waiting on others**: open booking requests, showing who, topic, expiry, and Copy link (guests only), Resend and Cancel. This replaces "Your requests".
  - **Upcoming**: the next 5 confirmed meetings.
- **"New appointment" dialog** (replaces the inline "Send a new request" card):
  - A segmented control: **Add to calendar now** / **Send booking request**.
  - Who: **Client** (company picker, existing `get_clients`) / **Guest** (name and email).
  - Topic, then notes.
  - Add now: date and time pickers with a hint ("Outside your hours" / "Clashes with …").
  - Booking request: a window (next 7 / 14 / 30 days, or custom), plus a live count such as "23 open slots".
- **Meeting detail dialog** (today read-only): shows the attendee (client account or guest), when it is (with timezone and duration), the source, and the status. Actions are Confirm/Reject, Cancel meeting (through `askConfirm`), and Download .ics.
- **Availability dialog**:
  - Timezone, meeting length, buffer, minimum notice and how far ahead.
  - A weekly grid: an on/off switch per day plus time ranges (add or remove a range).
  - A list of days off.
  - A preview line: "Next 7 days: 31 open slots".
- **Shared `assets/slot-picker.js`** (new): a day list on the left, time buttons on the right, timezone shown, and the viewer's local time shown alongside when it differs. It is used by:
  - **`book.html`** (new public guest page): host name and topic, the slot picker, name/email (pre-filled), an optional message, and Submit. It then shows "Sent: you'll get an email once it's confirmed". There are clear states for "expired", "already picked" and "link not valid".
  - **`dashboard.html`** (client): a staff booking request in the client's Approvals Hub shows **Pick a time**, which opens the slot picker in a dialog.

The client dashboard calendar keeps its phases and posts, because only the staff page changes.

## Demos (no PHP, no database, no login)
The project already has a demo system: `demo/build-demo.js` copies real pages into `demo/`, and `demo/demo-mock.js` (client) and `demo/demo-admin.js` (staff) answer every `api/*.php` call from sample data kept in sessionStorage, with a "Reset demo" ribbon. The demos extend that system.
- **Design options first (before PR 1)**: `demo/appointment-designs/`, following the `demo/phase-designs/` pattern. It holds 2–3 static layout options for the Appointments page and the booking page, so the layout can be chosen by clicking through them before any real build. The options:
  - a. Calendar plus right column.
  - b. Agenda-first list plus mini month.
  - c. Week time-grid full width, with the action panel in a drawer.
- **`demo/build-demo.js` `PAGES`, and `DEMO_PAGES` in `demo/demo-mock.js`**: add `admin-appointments.html` and `book.html`. Keep `admin-calendar.html` as the redirect stub, so the old link is tested too.
- **`demo/demo-admin.js`**:
  - Drop `calendarPosts` / `calendarPhases` from `get_admin_calendar`.
  - Add meetings with `source`, `duration_min` and guest fields, plus sample `booking_requests` (one open for a client, one open for a guest, one picked and waiting for confirmation, one expired).
  - Mock `get_availability` / `save_availability` (Mon–Fri 09:00–17:00, one day off), `add_meeting`, `create_booking_request` (returns a demo link), `cancel_booking_request`, `resend_booking_request`, `cancel_meeting`, and confirm/reject.
  - A JS port of `openSlots`, so slots react to availability edits.
  - Update the permission label at line 500 and `meetings_waiting.href` at line 467.
- **`demo/demo-mock.js`**:
  - Mock `api/public-booking.php` with fixed tokens covering each state: `book.html?t=demo-open`, `?t=demo-picked`, `?t=demo-booked`, `?t=demo-expired`, `?t=demo-invalid`.
  - Mock `get_booking_slots` / `pick_booking_slot` for the client's **Pick a time** in `dashboard.html`.
  - A pick made on `book.html` shows up under Needs your action on `admin-appointments.html` in the same tab, so the whole loop can be tried end to end: send request → open link → pick → confirm.
- **`demo/index.html`** (hand-written): add cards for "Appointments (staff)", "Guest booking page" (one link per state) and "Client: pick a time".
- Every PR finishes with `node demo/build-demo.js`, and the demo is checked in the browser preview at desktop and 375px widths.

## Delivery: three PRs on a new branch off `main`
1. **Rename and meetings only**: the new page shell and layout, the right column, an actionable detail dialog (confirm/reject/cancel with emails), the redirect stub, and the label changes.
2. **Availability**: tables, `api/availability.php`, the dialog, and working-hours shading on the calendar.
3. **Booking**: direct add, booking requests (client and guest), `book.html`, the slot picker, .ics emails, and the client "Pick a time".

The design document goes to `docs/plans/2026-11-15-appointments-design.md` in PR 1.

## Verification
- `php tests/api-v1/run.php` against a `_test` database, with a new `tests/api-v1/appointments.php` added to the run list. It covers:
  - Slot maths: hours, time off, buffer, notice, horizon, busy meetings, and timezone.
  - Direct add, including the clash warning.
  - Booking request: pick, then confirm (status `approved`), and pick, then reject (request closed).
  - A second pick of the same slot returns 409, and a second account in the same company is refused once the slot is picked.
  - Guest tokens: invalid, expired, reused.
  - Scope: one Account Manager cannot see another's guest requests, and the owner can.
  - SEO admin is refused.
  - `.ics` output: UID, UTC DTSTART/DTEND, CANCEL.
- Update `tests/api-v1/permissions.php:196`.
- Regenerate contract fixtures with `php tests/api-v1/run.php --fixtures`, then run `npm test` in `Wzone-ai` (`meetingItem` gains duration, timezone, guest and source).
- In the browser preview, sign in as Director and as Account Manager and check:
  - Add a meeting directly.
  - Send a guest request, open `book.html?t=…` in a second tab, pick a slot, then confirm it on the page.
  - Edit availability and watch the shading and open-slot count change.
  - Phone width (375px) and the old `admin-calendar.html?appointment=ID` link.
- Look at the emails and the .ics by pointing SMTP at a local catcher such as Mailpit, and import the .ics into Google or Outlook.
- After build, run a design review pass (design-critique / ux-audit skills) on the new page and `book.html`.
