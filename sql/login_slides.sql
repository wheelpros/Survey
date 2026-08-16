-- Slides shown beside the sign-in form on login.html.
--
-- Admins upload and order them from the "Login Page Slideshow" panel on
-- settings.html; api/login-slides.php reads them back for the unauthenticated
-- login page. With no rows the login page keeps its centred card, so an empty
-- table is a perfectly normal state.
--
-- Created lazily by ensureLoginSlidesTable() in api/db.php; this file is the
-- manual version, like sql/content_types.sql.

CREATE TABLE IF NOT EXISTS login_slides (
  id          INT AUTO_INCREMENT PRIMARY KEY,

  image_path  VARCHAR(255) NOT NULL,        -- web-relative, e.g. uploads/login-slides/<hex>.jpg

  title       VARCHAR(120)     NULL,        -- headline over the image
  subtitle    VARCHAR(255)     NULL,        -- one supporting line

  position    INT          NOT NULL DEFAULT 0,  -- 0-based; re-compacted on delete

  created_by  INT              NULL,        -- admins.id

  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_position (position, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
