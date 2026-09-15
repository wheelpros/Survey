-- Site-wide settings that are one value each rather than a column on anything.
-- api/db.php creates this automatically when the DB user may CREATE; run this
-- by hand otherwise.
--
-- Keys in use:
--   website_url  the public website address; the logo on inquiry.html links to it.

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
