-- The tables the portal expects to exist already - the ones api/db.php does
-- not create lazily. Reconstructed from the columns the endpoints read and
-- write; enough for the api/v1 tests, not a migration for a real database.
-- Everything else (appointments, notifications, projects, inquiries, OAuth,
-- client notes, settings) the endpoints create themselves on first use.

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password      VARCHAR(255) NOT NULL DEFAULT '',
  session_token VARCHAR(255)     NULL,
  approved      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admins (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  email               VARCHAR(190) NOT NULL,
  password            VARCHAR(255) NOT NULL DEFAULT '',
  role                VARCHAR(30)  NOT NULL,
  session_token       VARCHAR(255)     NULL,
  inquiries_access    TINYINT(1)   NOT NULL DEFAULT 0,
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  managed_by_admin_id INT              NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_user_assignments (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  user_id  INT NOT NULL,
  UNIQUE KEY uniq_assignment (admin_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE surveys (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  title                VARCHAR(255) NOT NULL,
  description          TEXT             NULL,
  assigned_user_id     INT          NOT NULL,
  status               VARCHAR(20)  NOT NULL DEFAULT 'pending_review',
  created_by_admin_id  INT              NULL,
  reviewed_by_admin_id INT              NULL,
  review_note          TEXT             NULL,
  reviewed_at          DATETIME         NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE survey_questions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  survey_id        INT          NOT NULL,
  question_text    TEXT         NOT NULL,
  question_type    VARCHAR(20)  NOT NULL DEFAULT 'input',
  required         TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  chips            TEXT             NULL,
  max_file_size_mb INT              NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE survey_responses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  survey_id    INT          NOT NULL,
  survey_title VARCHAR(255) NOT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'completed',
  submitted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE survey_answers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  response_id    INT  NOT NULL,
  question_id    INT  NOT NULL,
  question_label TEXT NOT NULL,
  answer         TEXT     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE survey_uploaded_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  response_id   INT          NOT NULL,
  question_id   INT          NOT NULL,
  user_id       INT          NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  file_path     VARCHAR(255) NOT NULL,
  file_type     VARCHAR(100)     NULL,
  file_size     INT              NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sql/content.sql
CREATE TABLE content (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(255) NOT NULL,
  client        VARCHAR(120)     NULL,
  link          VARCHAR(500)     NULL,
  caption       TEXT             NULL,
  content_type  VARCHAR(60)  NOT NULL,
  type_label    VARCHAR(80)      NULL,
  platform      VARCHAR(60)      NULL,
  category      VARCHAR(60)      NULL,
  orientation   ENUM('horizontal','vertical') NOT NULL DEFAULT 'horizontal',
  media_path    VARCHAR(255)     NULL,
  post_date     DATE             NULL,
  post_time     TIME             NULL,
  publish_now   TINYINT(1)   NOT NULL DEFAULT 0,
  status        ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft',
  created_by    INT              NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
