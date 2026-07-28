-- Hidden Field Grade for mission equipment.
--
-- Field Grade is authored only in Mission Control. Each equipped point adds
-- 0.10 percentage points to contract success, but the source value is omitted
-- from all player-facing gear payloads. It also contributes to the editor's
-- suggested iLvl; the stored release iLvl remains administrator-authored.
--
-- Run manually in phpMyAdmin after sql/migration_mission_item_levels.sql.
-- Idempotent: safe to run from the top in phpMyAdmin.

ALTER TABLE `game_loot_definitions`
  ADD COLUMN IF NOT EXISTS `field_grade` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `item_level`;

-- Percent rolls already resolve to hundredths. Preserve that precision in
-- mission history too, otherwise the 0.10% per-point Field Grade bonus would
-- be rounded away until several points had accumulated.
ALTER TABLE `game_player_missions`
  MODIFY COLUMN `success_percent` DECIMAL(5,2) NULL;
