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

-- If the database already had a `site_settings` of its own, the CREATE above
-- did nothing at all - that is what CREATE TABLE IF NOT EXISTS means - and
-- saving a setting used to fail with:
--
--     SQLSTATE[42S22]: Column not found: 1054 Unknown column 'setting_key' in 'field list'
--
-- api/db.php settles that by itself now. It writes to the existing table when
-- that table is a real key/value store under other column names (`key`/`value`,
-- `name`/`value`, `option_name`/`option_value`, and a few more, with a unique
-- index on the key). When it is not - a one-row settings table from another
-- app, say - it leaves that table alone and keeps our settings beside it:
--
--     CREATE TABLE IF NOT EXISTS app_site_settings (
--         setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
--         setting_value TEXT NULL,
--         updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
--     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- Run that one by hand only when the DB user may not CREATE through the app.
-- Nobody else's table is ever altered: a one-row settings table usually has
-- NOT NULL columns of its own with no defaults, so adding two columns to it
-- would buy a working schema and an INSERT that still fails.
