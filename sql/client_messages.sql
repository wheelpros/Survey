-- A client account's chat with its account managers, behind messages.html
-- (api/client-messages.php) and the Clients tab of admin-messages.html.
--
-- One chat per account, so a message hangs straight off the account's id:
-- user_id is the owner's users.id, as in every other client table, and the
-- sender is a kind ('user' or 'admin') plus an id. Whoever is on the account,
-- and whoever is its account manager today, reads the whole history.
--
-- client_message_reads holds each person's read marker (the id of the last
-- message they have seen) and how far they have been emailed about unread
-- messages. client_message_jobs keeps the email sweep to once a minute.
--
-- Private: the client's people and its account managers only - not the
-- Director, not anyone else on the client's team. Nothing here is copied into
-- notifications or the event log.
--
-- Added lazily by ensureClientMessageTables() in api/db.php; this file is the
-- manual version.

CREATE TABLE IF NOT EXISTS client_messages (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT         NOT NULL,
  sender_kind VARCHAR(10) NOT NULL,
  sender_id   INT         NOT NULL,
  sender_name VARCHAR(190) NOT NULL DEFAULT '',
  body        TEXT        NOT NULL,
  created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account (user_id, id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_message_reads (
  user_id          INT         NOT NULL,
  reader_kind      VARCHAR(10) NOT NULL,
  reader_id        INT         NOT NULL,
  last_read_id     BIGINT      NOT NULL DEFAULT 0,
  emailed_up_to_id BIGINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, reader_kind, reader_id),
  KEY idx_reader (reader_kind, reader_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_message_jobs (
  name   VARCHAR(40) NOT NULL PRIMARY KEY,
  ran_at TIMESTAMP   NOT NULL DEFAULT '2000-01-01 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO client_message_jobs (name) VALUES ('email_sweep');
