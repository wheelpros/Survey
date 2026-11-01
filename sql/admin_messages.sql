-- Direct messages between admins, behind admin-messages.html
-- (api/admin-messages.php).
--
-- One conversation per pair of admins: the two ids are stored smaller first
-- (admin_low, admin_high) under a unique key, so a pair always shares one
-- row. low_last_read_id / high_last_read_id are each side's read marker - the
-- id of the last message they have seen.
--
-- Private: only the two admins in a conversation read it, the Director
-- included. Nothing here is copied into notifications, the event log or the
-- MCP.
--
-- Added lazily by ensureAdminMessageTables() in api/db.php; this file is the
-- manual version, like sql/notifications.sql.

CREATE TABLE IF NOT EXISTS admin_conversations (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  admin_low         INT       NOT NULL,
  admin_high        INT       NOT NULL,
  low_last_read_id  BIGINT    NOT NULL DEFAULT 0,
  high_last_read_id BIGINT    NOT NULL DEFAULT 0,
  last_message_id   BIGINT    NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pair (admin_low, admin_high),
  KEY idx_low (admin_low, updated_at),
  KEY idx_high (admin_high, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_messages (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT       NOT NULL,
  sender_id       INT       NOT NULL,
  body            TEXT      NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conversation (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
