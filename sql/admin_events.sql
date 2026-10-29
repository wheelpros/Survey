-- The Director's event log behind event-log.html and the MCP tool
-- list_admin_events: one row per change an admin made, in the browser
-- (via 'web') or through an AI assistant (via 'mcp').
--
-- actor_name/actor_role and target_label are copied in when the row is
-- written, so it still reads right after the admin is removed or the item
-- renamed. No foreign keys on purpose.
--
-- `action` is "<area>.<verb>" (project.updated, admin.role_changed...); the
-- area before the dot is what the page's Area filter matches on.
--
-- Rows older than 12 months are deleted when the log is read
-- (api/admin-events.php) - there is no cron.
--
-- Added lazily by ensureAdminEventsTable() in api/db.php; this file is the
-- manual version, like sql/notifications.sql.

CREATE TABLE IF NOT EXISTS admin_events (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_admin_id INT          NOT NULL,
  actor_name     VARCHAR(150) NOT NULL,
  actor_role     VARCHAR(30)  NOT NULL,
  via            VARCHAR(10)  NOT NULL DEFAULT 'web',
  action         VARCHAR(64)  NOT NULL,
  target_type    VARCHAR(30)  NOT NULL,
  target_id      INT              NULL,
  target_label   VARCHAR(200)     NULL,
  client_id      INT              NULL,
  summary        VARCHAR(500) NOT NULL,
  ip             VARCHAR(45)      NULL,
  request_id     VARCHAR(64)      NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_actor (actor_admin_id, id),
  KEY idx_client (client_id, id),
  KEY idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
