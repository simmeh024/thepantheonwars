-- Severe weather: a witnessable event with its own award and notification.
--
-- Run once in phpMyAdmin after deploying the accompanying code. Every statement
-- is idempotent, so a partial or repeated run is safe. Deploy order is not
-- load-bearing: the witness endpoint fails soft and the weather card renders
-- its alert regardless of whether this has been applied.

-- ---------------------------------------------------------------------------
-- 1. Severe weather is a discoverable lore event.
--
-- Reuses user_lore_discoveries rather than adding a table: the unique key on
-- (user_id, entity_type, entity_id) already gives exactly the "first time only"
-- guarantee needed, and entity_id holds the world id.
--
-- Note this is once per world, not once per storm. Witnessing Neoh's weather at
-- its worst is the collectible moment; re-awarding every time the generator
-- rolls another storm would turn it into an attendance prize.
-- ---------------------------------------------------------------------------

ALTER TABLE user_lore_discoveries
  MODIFY COLUMN entity_type ENUM('world','overlord','timeline_event','severe_weather') NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. Its own reward rule, so the points are tunable in Reputation Control
-- rather than hardcoded. Worth more than an ordinary first visit because it
-- cannot be sought out on demand -- the weather has to actually be severe.
-- ---------------------------------------------------------------------------

INSERT INTO reputation_reward_rules (`key`, label, base_points, is_enabled) VALUES
  ('severe_weather_witnessed', 'Witness severe weather on a world', 4, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ---------------------------------------------------------------------------
-- 3. Notification type and its opt-out, following the existing pattern for
-- every other type.
-- ---------------------------------------------------------------------------

ALTER TABLE notifications
  MODIFY COLUMN type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message','new_device_login','warning_issued','weather_alert') NOT NULL;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS notif_weather_alert TINYINT(1) NOT NULL DEFAULT 1;
