-- The Director's permission changes behind Settings > Permissions and
-- api/permissions.php. A row exists only where the Director changed a
-- default: role_permissions for everyone in a role, admin_permission_overrides
-- for one admin (which beats the role). The defaults live in PERMISSIONS.
--
-- Added lazily by ensurePermissionTables() in api/db.php; this file is the
-- manual version, like sql/admin_events.sql.

CREATE TABLE IF NOT EXISTS role_permissions (
  role       VARCHAR(30) NOT NULL,
  permission VARCHAR(64) NOT NULL,
  allowed    TINYINT(1)  NOT NULL,
  updated_by INT             NULL,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_permission_overrides (
  admin_id   INT         NOT NULL,
  permission VARCHAR(64) NOT NULL,
  allowed    TINYINT(1)  NOT NULL,
  updated_by INT             NULL,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An account manager's old inquiries_access flag, as an override.
INSERT IGNORE INTO admin_permission_overrides (admin_id, permission, allowed)
SELECT id, 'inquiries.read', 1 FROM admins
WHERE role = 'account_manager' AND inquiries_access = 1;
