-- Contract progression for mission rewards.
--
-- Run manually in phpMyAdmin after sql/migration_mission_crew_stats.sql,
-- sql/migration_mission_gear.sql, sql/migration_mission_item_levels.sql, and
-- sql/migration_mission_loot_table_gear.sql.
--
-- A mission owns its progression target because its reusable loot tables may
-- also be attached to another contract. The 0 values keep legacy missions
-- unrestricted until an administrator authors a recommended iLvl and band.
-- featured_slots stores up to two comma-separated keys from the fixed seven
-- wearable slots; it is validated in Mission Control and never accepts free
-- form content.
--
-- Idempotent: safe to run from the top in phpMyAdmin.

ALTER TABLE `game_mission_definitions`
  ADD COLUMN IF NOT EXISTS `contract_tier` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `loot_rolls`,
  ADD COLUMN IF NOT EXISTS `recommended_item_level` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `contract_tier`,
  ADD COLUMN IF NOT EXISTS `reward_item_level_min` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `recommended_item_level`,
  ADD COLUMN IF NOT EXISTS `reward_item_level_max` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reward_item_level_min`,
  ADD COLUMN IF NOT EXISTS `featured_slots` VARCHAR(120) NOT NULL DEFAULT '' AFTER `reward_item_level_max`;
