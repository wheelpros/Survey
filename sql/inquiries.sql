-- ---------------------------------------------------------------------------
-- Inquiries: the consultation forms handed out as one-off links
-- ---------------------------------------------------------------------------
--
-- A client-facing consultation sheet. The owner writes the questions once and
-- shares one link, built from the inquiry's name:
--
--   inquiries                 one consultation template
--   inquiry_fields            the questions on it
--   inquiry_invites           one row per submission, and the per-person links
--                             handed out before links became name-only
--   inquiry_responses         one submission, pointing at its invite row
--   inquiry_response_answers  the answer to one field of one submission
--
-- api/db.php creates all five lazily in ensureInquiryTables(), the same way
-- sql/projects.sql is mirrored by ensureProjectTables(). This file is the
-- manual version, to run by hand against a fresh database - or, for the three
-- columns the redesign added, against an existing one.
--
-- No FOREIGN KEY constraints, matching every other table here: relations are
-- plain INT columns with a comment naming the target, and integrity is enforced
-- in PHP (api/admin-inquiries.php deletes an inquiry's fields, invites and
-- responses by hand).
--
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- inquiries
-- ---------------------------------------------------------------------------
--
-- `slug` is the readable half of a public link:
--
--     inquiry.html?name=free-30-minute-business-growth-consultation
--
-- Derived from the title by slugifyInquiryTitle() in api/admin-inquiries.php
-- and re-derived whenever the title changes, so a link copied under an old
-- title stops resolving - deliberate, and the reason the endpoint checks the
-- name against the token rather than trusting either alone.
--
-- `status` is the only switch that closes a link. Nothing expires on a clock,
-- and a link is not single-use - anyone holding it can answer while the inquiry
-- is active. See the note at the bottom of this file.
--
CREATE TABLE IF NOT EXISTS inquiries (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  title                    VARCHAR(200) NOT NULL,
  intro_text               TEXT             NULL,
  slug                     VARCHAR(200)     NULL,
  status                   VARCHAR(20)  NOT NULL DEFAULT 'active',   -- 'active' | 'inactive'
  account_manager_admin_id INT              NULL,                    -- admins.id, role account_manager
  created_by_admin_id      INT              NULL,                    -- admins.id
  created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slug (slug),
  KEY idx_manager (account_manager_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- inquiry_fields
-- ---------------------------------------------------------------------------
--
-- Rewritten wholesale on every save: the PUT branch deletes every row for the
-- inquiry and re-inserts. That is why editing is refused once an answer exists
-- - the answers point at these ids.
--
-- 'choice' takes any number of answers and 'select' exactly one; both read
-- their offer from `options`, one per line, and both store what came back as
-- text - a multi answer joined with ", ". Nothing ever parses that back apart,
-- so an option containing a comma is displayed, never re-split.
--
CREATE TABLE IF NOT EXISTS inquiry_fields (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  inquiry_id  INT          NOT NULL,                        -- inquiries.id
  field_label VARCHAR(200) NOT NULL,
  field_type  VARCHAR(20)  NOT NULL DEFAULT 'input',        -- 'input' | 'textarea' | 'choice' | 'select'
  options     TEXT             NULL,                       -- one per line, for the two list types
  required    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_inquiry (inquiry_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- inquiry_invites
-- ---------------------------------------------------------------------------
--
-- Two kinds of row live here. A submission through a name-only link writes one
-- already marked 'answered', so inquiry_responses.invite_id has something to
-- point at. A row still 'pending' is a per-person link handed out before the
-- change, and those stay single-use: api/public-inquiry.php claims them with a
-- conditional UPDATE, so two people racing on one still yield one answer.
--
-- `expires_at` is kept for rows written while links still died after 24 hours.
-- Nothing sets it any more - createInvite() inserts NULL - and no endpoint
-- reads it.
--
CREATE TABLE IF NOT EXISTS inquiry_invites (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  inquiry_id INT          NOT NULL,                          -- inquiries.id
  slug       VARCHAR(64)  NOT NULL,                          -- the ?token= value on a legacy link
  status     VARCHAR(20)  NOT NULL DEFAULT 'pending',        -- 'pending' | 'answered' | 'expired'
  expires_at DATETIME         NULL,                          -- historical, always NULL now
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_invite_slug (slug),
  KEY idx_inquiry_status (inquiry_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- inquiry_responses / inquiry_response_answers
-- ---------------------------------------------------------------------------
--
-- A response has no name or email column: whoever answered is only knowable
-- from the answers themselves, which is why the admin pages guess a display
-- name from a field labelled something like "Name".
--
CREATE TABLE IF NOT EXISTS inquiry_responses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  inquiry_id   INT       NOT NULL,                           -- inquiries.id
  invite_id    INT       NOT NULL,                           -- inquiry_invites.id
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inquiry (inquiry_id, submitted_at),
  KEY idx_invite (invite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inquiry_response_answers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  response_id INT  NOT NULL,                                 -- inquiry_responses.id
  field_id    INT  NOT NULL,                                 -- inquiry_fields.id
  answer_text TEXT     NULL,
  KEY idx_response (response_id),
  KEY idx_field (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Columns added to an inquiries table that already exists
-- ---------------------------------------------------------------------------
--
-- ensureInquiryTables() in api/db.php runs these itself when the DB user is
-- allowed to ALTER. Run them by hand if it is not.
--
-- ALTER TABLE inquiries ADD COLUMN slug VARCHAR(200) NULL;
-- ALTER TABLE inquiries ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active';
-- ALTER TABLE inquiries ADD COLUMN account_manager_admin_id INT NULL;
-- ALTER TABLE inquiries ADD UNIQUE KEY uniq_slug (slug);
-- ALTER TABLE inquiry_fields ADD COLUMN options TEXT NULL;


-- ---------------------------------------------------------------------------
-- Links that were killed by the old 24-hour timer
-- ---------------------------------------------------------------------------
--
-- Not run automatically: reviving a link is a decision about outreach already
-- sent, not a schema change. Every row marked 'expired' got there from the
-- timer alone - nothing else ever wrote that value - so both statements are
-- safe, but they are yours to choose.
--
-- UPDATE inquiry_invites SET status = 'pending', expires_at = NULL WHERE status = 'expired';
-- UPDATE inquiry_invites SET expires_at = NULL WHERE status = 'pending';
