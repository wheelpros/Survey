-- ---------------------------------------------------------------------------
-- Project Management: the engagements everything else hangs off
-- ---------------------------------------------------------------------------
--
-- Forms, Responses, Content and Calendar all describe work done FOR a client,
-- but nothing recorded the piece of work itself - its timeline, who owns it,
-- how far along it is. These three tables are that record, and they ship as a
-- unit because none of them is useful alone:
--
--   projects         one engagement for one client
--   project_tasks    the updates published against a project
--   project_members  which admins are working on it
--
-- api/db.php creates all three lazily in ensureProjectTables(), the same way
-- sql/notifications.sql is mirrored by ensureNotificationsTable(). This file is
-- the manual version, to run by hand against a fresh database.
--
-- No FOREIGN KEY constraints, matching every other table here: relations are
-- plain INT columns with a comment naming the target, and integrity is enforced
-- in PHP (api/projects.php deletes the tasks and members of a project by hand).
--
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- projects
-- ---------------------------------------------------------------------------
--
-- Two different questions live in this table and they are deliberately kept
-- apart:
--
--   `status`   what phase the team says it is in. An editorial label, set by a
--              human, and what the Project Details panel prints.
--
--   the stat   where the project sits on the calendar. Arithmetic, never
--   cards      stored - see the CASE expression in api/projects.php:
--
--                completed  status = 'completed'
--                upcoming   status <> 'completed' AND start_date >  CURDATE()
--                active     status <> 'completed' AND start_date <= CURDATE()
--
-- Those three buckets are mutually exclusive and sum exactly to the total,
-- which is why the status vocabulary has no 'on_hold' or 'cancelled' - either
-- one would create a project belonging to no card and make the four numbers on
-- the page stop adding up.
--
-- Completion is stored rather than derived because a project can finish two
-- weeks early or overrun by a month; `end_date < CURDATE()` would quietly mark
-- every late project done, which is exactly the number a manager must not be
-- lied to about.
--

CREATE TABLE IF NOT EXISTS projects (
  id                       INT AUTO_INCREMENT PRIMARY KEY,

  client_id                INT              NOT NULL,   -- users.id, the client this is for
  title                    VARCHAR(200)     NOT NULL,   -- "Q3 Customer Satisfaction"
  description              TEXT                 NULL,   -- plain text from the form's textarea

  project_type             VARCHAR(60)      NOT NULL DEFAULT 'survey',
                                                        -- survey | research | campaign | audit |
                                                        -- consulting | other. Validated against
                                                        -- PROJECT_TYPES in api/projects.php, not an
                                                        -- ENUM: adding a type must never need an
                                                        -- ALTER the DB user may not have.

  start_date               DATE             NOT NULL,   -- a plan, not a fact
  end_date                 DATE             NOT NULL,   -- a plan, not a fact. Past it, the project
                                                        -- is still Active until somebody says
                                                        -- otherwise.

  progress                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                                                        -- 0-100, moved by hand on the form's
                                                        -- slider. Never derived from the tasks:
                                                        -- the form labels it PROGRESS (MANUAL).

  status                   VARCHAR(20)      NOT NULL DEFAULT 'planning',
                                                        -- planning | active | review | completed.
                                                        -- Only 'completed' changes any arithmetic.

  image_path               VARCHAR(255)         NULL,   -- web-relative, uploads/projects/<hex>.jpg

  account_manager_admin_id INT                  NULL,   -- admins.id, printed as MANAGER
  created_by_admin_id      INT                  NULL,   -- admins.id, NULL if that account is gone

  created_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                     ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_client       (client_id),
  KEY idx_manager      (account_manager_admin_id),
  KEY idx_status_dates (status, start_date),
  KEY idx_type         (project_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- project_tasks
-- ---------------------------------------------------------------------------
--
-- One update published against a project. `orientation`, `image_path` and the
-- draft/published split are the same ideas as the `content` table, because the
-- card that renders a task is the same card that renders a content post.
--
-- `status` and `is_complete` are two columns rather than one because they are
-- genuinely orthogonal: finished work can still be sitting in draft, and a
-- published update can describe work that is only half done. The card badge
-- reads is_complete; the DRAFT badge reads status.
--
-- `scheduled_date` gates client visibility. There is no cron in this codebase,
-- so nothing moves a task from scheduled to live on its own - instead the read
-- is filtered, and api/project-tasks.php holds the one predicate that does it:
--
--   status = 'published' AND (scheduled_date IS NULL OR scheduled_date <= CURDATE())
--
-- Admins see every task regardless; the filter is there so the client-facing
-- page that comes later is additive rather than a data migration.
--
-- The client company is NOT stored here. Unlike `content.client`, a task
-- reaches its client through project_id -> projects.client_id, and that link is
-- solid, so the name on the card comes from the join.
--

CREATE TABLE IF NOT EXISTS project_tasks (
  id                  INT AUTO_INCREMENT PRIMARY KEY,

  project_id          INT          NOT NULL,   -- projects.id
  title               VARCHAR(200) NOT NULL,   -- TASK NAME
  link                VARCHAR(500)     NULL,   -- TASK LINK, http(s) only, as content.link
  description         TEXT             NULL,   -- HTML from the rich-text editor, 2000 chars of text

  orientation         ENUM('horizontal','vertical') NOT NULL DEFAULT 'horizontal',
                                               -- same two values and same meaning as
                                               -- content.orientation: it picks the card layout

  image_path          VARCHAR(255)     NULL,   -- web-relative, uploads/project-tasks/<hex>.jpg

  scheduled_date      DATE             NULL,   -- the form's "Update Date"; also what the month
                                               -- grid on the details page plots
  scheduled_time      TIME             NULL,   -- "Update Time"

  is_complete         TINYINT(1)   NOT NULL DEFAULT 0,
                                               -- the card badge: 1 = COMPLETED, 0 = ACTIVE. Set by
                                               -- the "Mark as complete" toggle, never by a date.

  status              VARCHAR(20)  NOT NULL DEFAULT 'draft',
                                               -- draft | published. Nothing else - there is no
                                               -- review step on tasks.

  created_by_admin_id INT              NULL,   -- admins.id, the author

  client_notified_at  DATETIME         NULL,   -- stamped the first time this update was announced
                                               -- to the client. NULL means never announced, which
                                               -- is what makes the notification fire exactly once

  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_project          (project_id, id),
  KEY idx_project_schedule (project_id, scheduled_date),
  KEY idx_author           (created_by_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- project_members
-- ---------------------------------------------------------------------------
--
-- The TEAM MEMBERS multi-select on the project form. Members are ADMINS, not
-- users - "the people who'll work on it".
--
-- The account manager is deliberately not inserted here. It has its own column
-- on `projects` because "who signs this off" and "who works on it" are
-- different questions, and the details panel prints them as different fields.
--
-- Membership widens what an admin can see. Everywhere else in this system a
-- non-owner reaches a client through admin_user_assignments; here a project is
-- also visible if you manage it or are a member of it (see projectScope() in
-- api/projects.php). Without that clause TEAM MEMBERS would be decoration - the
-- people assigned to a project could not open it.
--
-- Written as a full replace - DELETE by project_id, then re-INSERT - inside a
-- transaction, the same shape api/admin-user-assignments.php uses.
--

CREATE TABLE IF NOT EXISTS project_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,

  project_id INT       NOT NULL,   -- projects.id
  admin_id   INT       NOT NULL,   -- admins.id

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_member (project_id, admin_id),
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
