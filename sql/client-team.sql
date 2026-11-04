-- Client teams: what api/client-account.php adds lazily, to run by hand when the
-- DB user may not ALTER or CREATE.

ALTER TABLE users
  ADD COLUMN account_owner_id INT NULL,
  ADD KEY idx_account_owner (account_owner_id);

ALTER TABLE survey_responses
  ADD COLUMN submitted_by_id INT NULL,
  ADD COLUMN submitted_by_name VARCHAR(150) NULL;

ALTER TABLE survey_uploaded_files
  ADD COLUMN uploaded_by_id INT NULL;

ALTER TABLE appointments
  ADD COLUMN requested_by_id INT NULL,
  ADD COLUMN requested_by_name VARCHAR(150) NULL,
  ADD COLUMN responded_by_id INT NULL,
  ADD COLUMN responded_by_name VARCHAR(150) NULL,
  ADD COLUMN responded_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS client_invites (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT          NOT NULL,
  email       VARCHAR(190) NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  invited_by  INT          NOT NULL,
  expires_at  INT          NOT NULL,
  accepted_at DATETIME         NULL,
  revoked_at  DATETIME         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token_hash),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
