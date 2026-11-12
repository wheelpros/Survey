-- A due date on phases and content posts, set beside priority on
-- project-task-form.html and admin-content-form.html. The admin dashboard's
-- Due column reads it, falling back to the phase's date or the post's date.
--
-- api/db.php adds both lazily (PROJECT_TASK_EXTRA_COLUMNS and
-- CONTENT_EXTRA_COLUMNS); this is the same change to run by hand when the DB
-- user is not allowed to ALTER.

ALTER TABLE project_tasks
  ADD COLUMN due_date DATE NULL;

ALTER TABLE content
  ADD COLUMN due_date DATE NULL;
