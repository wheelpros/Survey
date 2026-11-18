-- Departments: what admins are grouped by (Settings > Departments).
--
-- Daily reports are planned by department and Tracking filters by it.
-- Archiving a department (active = 0) hides it from new choices; admins and
-- reports already in it keep it.
--
-- Added lazily by ensureDepartmentsTable() in api/db.php; this file is the
-- manual version.

CREATE TABLE IF NOT EXISTS departments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The admin's department (NULL: none yet).
ALTER TABLE admins ADD COLUMN department_id INT NULL;
