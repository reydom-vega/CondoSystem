-- Existing installations: prefer scripts/migrate_profile_photos.php --apply,
-- which checks for the column before applying this additive change.
ALTER TABLE users ADD COLUMN profile_photo VARCHAR(80) DEFAULT NULL;
