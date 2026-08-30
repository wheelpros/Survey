-- Announcements: the one notification an admin writes by hand.
--
-- Everything else in the notifications table is a side effect of an event
-- (a form approved, a post published). An announcement is the owner sitting
-- down and typing something, so it carries more than a bold line and a link:
-- a description, an optional date and an optional image.
--
-- The row here is the message written once. The fan-out to the people who
-- should read it stays in notifications, one row per recipient, each carrying
-- announcement_id back to this table - so a hundred recipients cost a hundred
-- short rows and one copy of the text and the image path.
--
-- `audience` records who it was aimed at when it was sent ('team' = admins,
-- 'client' = portal users). It is history, not a filter: reading is still
-- driven by the recipient's own notifications rows.
--
-- Added lazily by ensureAnnouncementsTable() in api/db.php; this file is the
-- manual version, like sql/notifications.sql.

CREATE TABLE IF NOT EXISTS announcements (
  id                  INT AUTO_INCREMENT PRIMARY KEY,

  audience            VARCHAR(10)  NOT NULL,   -- 'team' (admins) or 'client' (portal users)

  title               VARCHAR(200) NOT NULL,
  body                TEXT             NULL,   -- the description, shown in full on the panel
  event_date          DATE             NULL,   -- informational only: nothing is scheduled on it

  image_path          VARCHAR(255)     NULL,   -- web-relative, e.g. uploads/announcements/<hex>.jpg

  created_by_admin_id INT              NULL,   -- admins.id, NULL if that account is gone

  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
