-- Profile fields shown on profile.html.
-- api/db.php adds these automatically when the DB user may ALTER; run this by
-- hand otherwise.

ALTER TABLE users ADD COLUMN company_name VARCHAR(150) NULL;
ALTER TABLE users ADD COLUMN website      VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN description  TEXT         NULL;
ALTER TABLE users ADD COLUMN phone        VARCHAR(50)  NULL;
