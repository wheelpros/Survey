-- Phases: the fields and tables the Phase and Edit Phase pages added to
-- project tasks. ensureProjectTables() in api/db.php does the same on the
-- first request; this is the version to run by hand for a database user
-- without ALTER/CREATE rights.

ALTER TABLE project_tasks
  ADD COLUMN phase_label VARCHAR(80) NULL,
  ADD COLUMN role        VARCHAR(80) NULL,
  ADD COLUMN priority    VARCHAR(10) NOT NULL DEFAULT 'medium',
  ADD COLUMN notes       TEXT        NULL;

CREATE TABLE IF NOT EXISTS project_task_teammates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT        NOT NULL,
  admin_id   INT        NOT NULL,
  is_done    TINYINT(1) NOT NULL DEFAULT 0,
  done_at    DATETIME       NULL,
  created_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teammate (task_id, admin_id),
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_task_assignments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT         NOT NULL,
  admin_id   INT         NOT NULL,
  work_note  TEXT            NULL,
  status     VARCHAR(12) NOT NULL DEFAULT 'todo',
  created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_task  (task_id),
  KEY idx_admin (admin_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
