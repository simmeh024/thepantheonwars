-- Temporary 2x/3x/4x reputation events use existing app_settings rows, so
-- only message likes need a schema addition. Store the exact original award
-- so an unlike always reverses the correct number after an event expires.
ALTER TABLE message_likes
  ADD COLUMN IF NOT EXISTS reputation_awarded SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id;

-- Likes created before this feature each granted the standard 2 reputation.
UPDATE message_likes SET reputation_awarded = 2 WHERE reputation_awarded = 0;
