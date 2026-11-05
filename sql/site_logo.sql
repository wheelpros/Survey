-- The portal's logo, served at /logo.png by api/site-logo.php. One row, id 1.
-- In the database because a file in the container is lost on every deploy.
--
-- Added lazily by ensureSiteLogoTable() in api/site-logo-store.php; this file
-- is the manual version, for a DB user that isn't allowed to CREATE.

CREATE TABLE IF NOT EXISTS site_logo (
  id         TINYINT      NOT NULL PRIMARY KEY,
  mime       VARCHAR(40)  NOT NULL,
  version    CHAR(40)     NOT NULL,
  data       MEDIUMBLOB   NOT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
