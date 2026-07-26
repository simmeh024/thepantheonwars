-- Crew fatigue + mission-ready notifications
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> `pantheonwars` -> SQL tab).
-- Every statement is idempotent, so a run that stops partway can be repeated
-- from the top.
--
-- Fatigue is a spendable stamina pool, not an accumulating debt: 100 at rest,
-- spent when a crew member launches, and regenerated over time while they are
-- available. It is stored as the current value plus the moment it was last
-- settled, and regeneration is resolved lazily on read -- there is no cron
-- ticking every crew row, the same way the mission countdown is derived rather
-- than stored.
--
-- fatigue_updated_at is deliberately nullable. NULL means "nothing to catch up
-- on" for crew created before this migration or recruited afterwards, so they
-- read as their stored value rather than accruing regeneration from a zero
-- date.

ALTER TABLE game_player_crew
  ADD COLUMN IF NOT EXISTS fatigue SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER xp,
  ADD COLUMN IF NOT EXISTS fatigue_updated_at DATETIME NULL AFTER fatigue;

-- Existing crew start rested. The default covers rows created from here on;
-- this repairs any row that predates the column with a zeroed value.
UPDATE game_player_crew SET fatigue = 100 WHERE fatigue = 0 AND fatigue_updated_at IS NULL;

-- A run that finishes while the player is logged out is now settled by
-- api/cron/complete-missions.php (and lazily by api/missions/overview.php), so
-- there is something to tell the player about.
ALTER TABLE notifications
  MODIFY COLUMN type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message','new_device_login','warning_issued','weather_alert','mission_ready') NOT NULL;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS notif_mission_ready TINYINT(1) NOT NULL DEFAULT 1;
