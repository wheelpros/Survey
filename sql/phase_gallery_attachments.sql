-- Phases and posts: a gallery, attached files, an Assigned table on posts and
-- admin photos. api/db.php and api/upload-helpers.php add all of this on first
-- use; this is the same to run by hand for a read-only database user.

-- Up to five images beside a phase's cover, shown on the phase page only.
ALTER TABLE project_tasks ADD COLUMN gallery_paths TEXT NULL;

-- One assignment per person per phase (keep each person's latest row first).
DELETE s FROM project_task_assignments s
JOIN project_task_assignments newer
  ON newer.task_id = s.task_id AND newer.admin_id = s.admin_id AND newer.id > s.id;
ALTER TABLE project_task_assignments ADD UNIQUE KEY uniq_assignment (task_id, admin_id);

-- Staff-only files on a phase ('task') or a post ('content'), served by
-- api/attachment.php to admins only.
CREATE TABLE IF NOT EXISTS attachments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  owner_type    VARCHAR(12)  NOT NULL,
  owner_id      INT          NOT NULL,
  path          VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  ext           VARCHAR(8)   NOT NULL,
  size          INT          NOT NULL DEFAULT 0,
  uploaded_by   INT              NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A post's Assigned table: what each teammate does, and their own status.
ALTER TABLE content_assignees ADD COLUMN work_note TEXT NULL;
ALTER TABLE content_assignees ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'todo';
UPDATE content_assignees SET status = 'done' WHERE is_done = 1;

-- An admin's photo, set by the Director.
ALTER TABLE admins ADD COLUMN avatar_path VARCHAR(255) NULL;
