-- Content library: admin-authored posts shown to every approved user.
--
-- Reconstructed from the columns api/admin-content.php reads and writes; the
-- table already exists in the live database, so this is for fresh deploys and
-- as the record of the schema. Run by hand like sql/user_profile_fields.sql.
--
-- `orientation` is the important one: it is chosen by the admin at creation
-- time and decides which detail layout the user sees on content.html.

CREATE TABLE IF NOT EXISTS content (
  id            INT AUTO_INCREMENT PRIMARY KEY,

  title         VARCHAR(255) NOT NULL,
  client        VARCHAR(120)     NULL,
  caption       TEXT             NULL,        -- HTML from the caption editor

  content_type  VARCHAR(60)  NOT NULL,        -- instagram_post | reel | newsletter | ...
  type_label    VARCHAR(80)      NULL,        -- "Reel"        (custom types store their own)
  platform      VARCHAR(60)      NULL,        -- "Instagram"
  category      VARCHAR(60)      NULL,        -- "Social posts" - drives the filter tabs

  orientation   ENUM('horizontal','vertical') NOT NULL DEFAULT 'horizontal',

  media_path    VARCHAR(255)     NULL,        -- web-relative, e.g. uploads/content/<hex>.jpg

  post_date     DATE             NULL,
  post_time     TIME             NULL,
  publish_now   TINYINT(1)   NOT NULL DEFAULT 0,

  status        ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft',

  created_by    INT              NULL,        -- admins.id, resolved to a name on read

  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_status (status),
  KEY idx_category (category),
  KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
