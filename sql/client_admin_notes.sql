-- The internal note an account manager keeps against a client, shown on the
-- Details panel of user-details.html.
--
-- api/db.php adds this automatically when the DB user may ALTER; run it by hand
-- otherwise. It is not one of the profile columns: no endpoint a client can
-- reach selects or writes it.

ALTER TABLE users ADD COLUMN admin_notes TEXT NULL;
