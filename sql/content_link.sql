-- Link on the visual (content) records.
--
-- api/db.php adds it lazily via ensureContentColumns(); this is the same change
-- to run by hand when the DB user is not allowed to ALTER.
--
-- Note for databases that ran the earlier version of this file: it also added a
-- `language` column. The app no longer reads or writes it - reading direction is
-- part of the caption now, set with the alignment buttons on the editor - so the
-- column can stay as it is or be dropped, whichever you prefer.

ALTER TABLE content
  ADD COLUMN link VARCHAR(500) NULL AFTER client;
