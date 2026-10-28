-- admins.position: the job title shown in the sidebar and the account popup.
-- ensureAdminColumns() in api/db.php does the same on the first request; this is
-- the version to run by hand for a database user without ALTER rights.

ALTER TABLE admins ADD COLUMN position VARCHAR(100) NULL;
