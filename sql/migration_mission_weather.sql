-- Neoh's live weather, recorded against the run it affected.
--
-- The weather a crew launched into is the weather that judges them, so the
-- conditions are snapshotted here at launch rather than read again at claim: a
-- long operation can cross a UTC day boundary, and the forecast generator
-- deliberately produces a new stable sequence for each day. Without the
-- snapshot, a run launched into a static storm could be resolved against a
-- clear afternoon.
--
-- Only the observed facts are stored -- what the condition was, which of the
-- five icon families it belongs to, and whether it qualified as severe. The
-- modifiers themselves are code constants (PW_MISSION_WEATHER_* in
-- api/missions/missions-helpers.php) and are re-derived from these at claim, so
-- tuning them never requires a data migration.
--
-- Idempotent: safe to re-run from the top.

ALTER TABLE `game_player_missions`
  ADD COLUMN IF NOT EXISTS `weather_condition` VARCHAR(80) NULL DEFAULT NULL AFTER `world_key`,
  ADD COLUMN IF NOT EXISTS `weather_icon` VARCHAR(24) NULL DEFAULT NULL AFTER `weather_condition`,
  ADD COLUMN IF NOT EXISTS `weather_severe` TINYINT(1) NOT NULL DEFAULT 0 AFTER `weather_icon`;
