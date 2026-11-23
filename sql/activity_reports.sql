-- Activity reports: activities planned for one person, filled in and sent by
-- them with an end-of-day note, any number a day, reviewed activity by activity by
-- any one of its reviewers (daily_report_reviewers).
--
-- The rules are in api/activity-report-lib.php. Files are rows in `attachments`
-- with owner_type = 'daily_report'. Needs sql/departments.sql first.
--
-- Added lazily by ensureDailyReportTables() in api/db.php; this file is the
-- manual version.

CREATE TABLE IF NOT EXISTS daily_reports (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  admin_id          INT          NOT NULL,              -- who fills it in
  created_by        INT          NOT NULL,              -- who planned it (themselves for their own)
  reviewer_admin_id INT              NULL,              -- before daily_report_reviewers; no longer read
  department_id     INT              NULL,
  template_id       INT              NULL,
  title             VARCHAR(150)     NULL,              -- what it's for, given when it's planned
  report_date       DATE         NOT NULL,              -- the portal's day it was created on (a label)
  status            VARCHAR(10)  NOT NULL DEFAULT 'planned',  -- planned | submitted (sent) | reviewed
  started           TINYINT(1)   NOT NULL DEFAULT 0,    -- anything saved yet
  reviewer_note     TEXT             NULL,
  reviewed_by       INT              NULL,
  reviewed_at       DATETIME         NULL,
  submitted_at      DATETIME         NULL,
  outstanding       TEXT             NULL,              -- end of day: what's left
  priorities        TEXT             NULL,              -- end of day: tomorrow's priorities
  challenges        TEXT             NULL,              -- end of day: challenges or assistance required
  challenge_priority VARCHAR(6)      NULL,              -- low | medium | high
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME         NULL,
  KEY idx_person_day (admin_id, report_date),
  KEY idx_date (report_date),
  KEY idx_reviewer (reviewer_admin_id, status),
  KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Installs from before report titles:
--   ALTER TABLE daily_reports ADD COLUMN title VARCHAR(150) NULL;

-- Installs from before many reports a day had a unique key here. Swap it once:
--   ALTER TABLE daily_reports DROP INDEX uq_person_day, ADD KEY idx_person_day (admin_id, report_date);

CREATE TABLE IF NOT EXISTS daily_report_activities (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  report_id       INT          NOT NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  title           VARCHAR(200) NOT NULL,
  description     TEXT             NULL,
  origin          VARCHAR(10)  NOT NULL DEFAULT 'planned',   -- planned | carried | employee (added by the author)
  count_unit      VARCHAR(30)      NULL,                     -- "calls"; NULL when nothing is counted
  count_value     INT              NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'todo',      -- todo | in_progress | follow_up | done
  outcome         TEXT             NULL,
  done_at         DATETIME         NULL,
  review_state    VARCHAR(10)      NULL,                     -- the latest review: approved | changes
  review_feedback TEXT             NULL,
  reviewed_by     INT              NULL,
  reviewed_at     DATETIME         NULL,
  KEY idx_report (report_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who sees and reviews a report: the planner, or the people picked for your
-- own report. Any one of them completes the review.
CREATE TABLE IF NOT EXISTS daily_report_reviewers (
  report_id INT NOT NULL,
  admin_id  INT NOT NULL,
  PRIMARY KEY (report_id, admin_id),
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Earlier reviews of an activity, once a newer one replaced them.
CREATE TABLE IF NOT EXISTS daily_report_activity_reviews (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  activity_id INT         NOT NULL,
  state       VARCHAR(10) NOT NULL,
  feedback    TEXT            NULL,
  reviewed_by INT         NOT NULL,
  reviewed_at DATETIME    NOT NULL,
  KEY idx_activity (activity_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_report_templates (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(80)  NOT NULL,
  created_by        INT          NOT NULL,             -- edits and deletes: them, or the Director
  department_id     INT              NULL,
  reviewer_admin_id INT              NULL,             -- NULL: whoever plans the report
  activities        TEXT         NOT NULL,             -- JSON [{title, description, count_unit}]
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME         NULL,
  KEY idx_active (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
