-- Link + language on the visual (content) records.
--
-- api/db.php adds these lazily via ensureContentColumns(); this is the same
-- change to run by hand when the DB user is not allowed to ALTER.
--
-- `language` drives the reading direction of the post: an 'arabic' post is
-- rendered RTL on admin-content.html and content.html, 'english' stays LTR.

ALTER TABLE content
  ADD COLUMN link     VARCHAR(500) NULL AFTER client,
  ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'english' AFTER link;
