-- The notifications inbox behind notifications.html (clients) and
-- admin-notifications.html (admins).
--
-- One row is one message for one recipient. `recipient_kind` decides which
-- table `recipient_id` points at:
--
--   'user'   users.id   - the client sees it on notifications.html
--   'admin'  admins.id  - the admin sees it on admin-notifications.html
--
-- Fan-out happens when the event fires, not when the page is read: something
-- aimed at "every admin assigned to this client" is inserted once per admin,
-- so reading is one indexed lookup and marking a row read touches nobody
-- else. api/notify.php does the writing.
--
-- `link` is a ready-made relative URL (e.g. admin-form-builder.html?edit=42)
-- built at the moment the event fires. Both pages render it verbatim, which
-- keeps them free of any per-event-type logic.
--
-- `announcement_id` is the one exception to that: an announcement has nowhere
-- to link to, so the row carries a key into sql/announcements.sql instead and
-- the page opens its detail panel in place.
--
-- Rows are ordered by id, never created_at: a fan-out writes N rows inside
-- the same second, and second-granular ties make paging non-deterministic.
--
-- Added lazily by ensureNotificationsTable() in api/db.php; this file is the
-- manual version, like sql/appointment_requests.sql.

CREATE TABLE IF NOT EXISTS notifications (
  id             INT AUTO_INCREMENT PRIMARY KEY,

  recipient_kind VARCHAR(10)  NOT NULL,      -- 'user' or 'admin'
  recipient_id   INT          NOT NULL,      -- users.id or admins.id

  event_type     VARCHAR(40)  NOT NULL,      -- see the NOTIFY_* keys in api/notify.php

  title          VARCHAR(200) NOT NULL,      -- one line, shown bold
  body           VARCHAR(500)     NULL,      -- one supporting line
  link           VARCHAR(255)     NULL,      -- relative URL, or NULL for no deep link

  actor_kind     VARCHAR(10)      NULL,      -- who caused it: 'user' or 'admin'
  actor_id       INT              NULL,      -- users.id or admins.id

  read_at        DATETIME         NULL,      -- NULL means unread

  announcement_id INT             NULL,      -- announcements.id, for hand-written announcements only

  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_inbox  (recipient_kind, recipient_id, id),
  KEY idx_unread (recipient_kind, recipient_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
