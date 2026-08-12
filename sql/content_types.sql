-- Custom visual types added from admin-content-form.html.
--
-- The six built-in types (Instagram Post, Reel, Newsletter, ...) stay in the
-- form's `types` array. Anything an admin adds with the "+" chip is saved here
-- instead, so the next post can reuse it rather than re-creating it.
--
-- Created lazily by ensureContentTypesTable() in api/db.php; this file is the
-- manual version, like sql/user_profile_fields.sql.

CREATE TABLE IF NOT EXISTS content_types (
  id          INT AUTO_INCREMENT PRIMARY KEY,

  type_id     VARCHAR(60)  NOT NULL,        -- custom_tiktok_clip - matches content.content_type
  label       VARCHAR(80)  NOT NULL,        -- "TikTok Clip"
  platform    VARCHAR(60)  NOT NULL,        -- "TikTok"
  category    VARCHAR(60)  NOT NULL,        -- drives the filter tabs; defaults to the platform
  icon        VARCHAR(20)  NOT NULL DEFAULT 'plus',  -- camera | reel | mail | case | ad | chart

  created_by  INT              NULL,        -- admins.id

  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_type_id (type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
