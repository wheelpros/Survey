-- Appointments: availability and booking requests.
--
-- The same schema api/db.php creates on first use (ensureAppointmentTables()
-- and ensureBookingTables()); run by hand on a database whose app user can't
-- run DDL.
--
-- Times are wall-clock times in the staff member's own time zone.

-- Meetings: length, zone, guests (no users row), how they were made.
ALTER TABLE appointments
  MODIFY user_id INT NULL,
  ADD COLUMN duration_min       INT          NULL,
  ADD COLUMN timezone           VARCHAR(64)  NULL,
  ADD COLUMN guest_name         VARCHAR(150) NULL,
  ADD COLUMN guest_email        VARCHAR(190) NULL,
  ADD COLUMN source             VARCHAR(12)  NULL,   -- direct | booking | request | proposal
  ADD COLUMN booking_request_id INT          NULL,
  ADD COLUMN attendee_message   TEXT         NULL;

-- One row per staff member: when they can be booked.
-- weekly_json: {"mon":[["09:00","17:00"]],"tue":[...],...,"sun":[]}
CREATE TABLE IF NOT EXISTS staff_availability (
  admin_id       INT          NOT NULL PRIMARY KEY,
  timezone       VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  slot_minutes   INT          NOT NULL DEFAULT 30,
  buffer_minutes INT          NOT NULL DEFAULT 0,
  notice_hours   INT          NOT NULL DEFAULT 24,
  horizon_days   INT          NOT NULL DEFAULT 30,
  weekly_json    TEXT         NOT NULL,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_time_off (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  admin_id  INT          NOT NULL,
  date_from DATE         NOT NULL,
  date_to   DATE         NOT NULL,
  label     VARCHAR(120)     NULL,
  KEY idx_admin_dates (admin_id, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A booking request: one per client company or guest. status:
--   open      waiting for someone to pick a time
--   picked    a time was picked; the appointment waits on the host
--   closed    the picked time was confirmed or rejected
--   cancelled the host withdrew it
-- Expiry is not a status: a request is expired once window_to has passed.
CREATE TABLE IF NOT EXISTS booking_requests (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  admin_id       INT          NOT NULL,
  client         VARCHAR(150)     NULL,
  guest_name     VARCHAR(150)     NULL,
  guest_email    VARCHAR(190)     NULL,
  topic          VARCHAR(200) NOT NULL,
  notes          TEXT             NULL,
  duration_min   INT          NOT NULL,
  timezone       VARCHAR(64)  NOT NULL,
  window_from    DATE         NOT NULL,
  window_to      DATE         NOT NULL,
  status         VARCHAR(12)  NOT NULL DEFAULT 'open',
  appointment_id INT              NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_status (admin_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One emailed link per person: only the sha256 of the token is kept.
CREATE TABLE IF NOT EXISTS booking_request_links (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  booking_request_id INT          NOT NULL,
  user_id            INT              NULL,
  name               VARCHAR(150)     NULL,
  email              VARCHAR(190) NOT NULL,
  token_hash         CHAR(64)     NOT NULL,
  opened_at          DATETIME         NULL,
  used_at            DATETIME         NULL,
  UNIQUE KEY uniq_token (token_hash),
  KEY idx_request (booking_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
