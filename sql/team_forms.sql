-- Team forms: forms sent to admins, filled in by admins.
--
-- A survey always belongs to one client account. A team form goes the other
-- way: someone holding team_forms.send (the Director by default) sends it to
-- the whole team, to roles, or to specific admins, and each one fills it in
-- once and sends it back. The rules are in api/team-form-writes.php.
--
-- Added lazily by ensureTeamFormTables() in api/db.php; this file is the
-- manual version.

CREATE TABLE IF NOT EXISTS team_forms (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  title               VARCHAR(200) NOT NULL,
  description         TEXT             NULL,
  due_date            DATE             NULL,   -- shown to recipients; nothing closes on it
  status              VARCHAR(10)  NOT NULL DEFAULT 'open',   -- 'open' or 'closed'
  target_roles        VARCHAR(100)     NULL,   -- the roles picked, for the record
  created_by_admin_id INT          NOT NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at           DATETIME         NULL,
  KEY idx_sender (created_by_admin_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same shape as survey_questions.
CREATE TABLE IF NOT EXISTS team_form_questions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  form_id          INT          NOT NULL,
  question_text    TEXT         NOT NULL,
  question_type    VARCHAR(20)  NOT NULL DEFAULT 'input',
  required         TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  chips            TEXT             NULL,   -- JSON array of a checklist's options
  max_file_size_mb INT              NULL,
  KEY idx_form (form_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who got it, and whether they have answered.
CREATE TABLE IF NOT EXISTS team_form_recipients (
  form_id      INT         NOT NULL,
  admin_id     INT         NOT NULL,
  status       VARCHAR(10) NOT NULL DEFAULT 'pending',   -- 'pending' or 'submitted'
  submitted_at DATETIME        NULL,
  PRIMARY KEY (form_id, admin_id),
  KEY idx_admin (admin_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_form_answers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  form_id        INT  NOT NULL,
  admin_id       INT  NOT NULL,
  question_id    INT  NOT NULL,
  question_label TEXT NOT NULL,   -- the question as it read when answered
  answer         TEXT     NULL,   -- for a file, its original name
  file_path      VARCHAR(255) NULL,
  file_type      VARCHAR(100) NULL,
  KEY idx_person (form_id, admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notifications ADD COLUMN team_form_id INT NULL;
