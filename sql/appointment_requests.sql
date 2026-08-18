-- Meeting requests behind the two calendar pages: user-appointments.html and
-- admin-calendar.html.
--
-- A row in `appointments` is one request, in one of two directions:
--
--   requested_by = 'admin'  an admin asked a client for time. Waits on the
--                           user, who accepts or declines from the Approvals
--                           Hub on user-appointments.html.
--   requested_by = 'user'   a client asked the admin for time. Waits on an
--                           admin, from the Approvals Hub on
--                           admin-calendar.html.
--
-- status is 'pending', 'approved' or 'rejected'. The pages label an approved
-- row "Confirmed"; the column keeps the older wording so existing rows and
-- api/calendar.php agree.
--
-- Added lazily by ensureAppointmentTables() in api/db.php; this file is the
-- manual version, like sql/login_slides.sql.

CREATE TABLE IF NOT EXISTS appointments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,          -- users.id the meeting is with
  title      VARCHAR(200)     NULL,
  date       DATE         NOT NULL,
  time       TIME         NOT NULL,
  status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The columns the redesigned pages added. Skip any that already exist.
ALTER TABLE appointments ADD COLUMN topic        VARCHAR(200) NULL;
ALTER TABLE appointments ADD COLUMN notes        TEXT         NULL;
ALTER TABLE appointments ADD COLUMN requested_by VARCHAR(10)  NOT NULL DEFAULT 'admin';
ALTER TABLE appointments ADD COLUMN admin_id     INT          NULL;
ALTER TABLE appointments ADD COLUMN client       VARCHAR(150) NULL;
