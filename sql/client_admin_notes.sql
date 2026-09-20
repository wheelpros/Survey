-- The internal note an admin keeps against a client, shown on the Details panel
-- of user-details.html.
--
-- One row per (client, admin): a note belongs to whoever wrote it and only they
-- ever read it back, so two managers dealing with the same client each keep
-- their own and neither can see or overwrite the other's.
--
-- api/db.php creates this automatically when the DB user may CREATE; run it by
-- hand otherwise. No endpoint a client can reach selects or writes it.

CREATE TABLE IF NOT EXISTS client_admin_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT       NOT NULL,
    admin_id   INT       NOT NULL,
    note       TEXT          NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_client_admin (user_id, admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notes written before notes had an author lived in users.admin_notes, where
-- every admin could read them. Nothing records who wrote each one, so they go
-- to the owner: the one admin who could certainly see all of them already.
-- api/db.php runs this itself; it is here for a DB user that may not INSERT
-- from a SELECT. The old column is left alone - nothing reads it any more.
--
-- ALTER TABLE users ADD COLUMN admin_notes TEXT NULL;   -- the old column, if you still need it

INSERT IGNORE INTO client_admin_notes (user_id, admin_id, note)
SELECT u.id, (SELECT id FROM admins WHERE role = 'owner' ORDER BY id ASC LIMIT 1), u.admin_notes
FROM users u
WHERE u.admin_notes IS NOT NULL AND u.admin_notes <> '';
