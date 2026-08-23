-- Description on a form (survey).
--
-- api/db.php adds it lazily via ensureSurveyColumns(); this is the same change
-- to run by hand when the DB user is not allowed to ALTER.
--
-- It is the blurb the admin writes on admin-form-builder.html, shown to the
-- assigned user above the questions and to the account manager while they
-- review the form.

ALTER TABLE surveys
  ADD COLUMN description TEXT NULL AFTER title;
