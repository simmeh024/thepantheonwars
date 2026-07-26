-- Mission Research Locks
-- Run once in phpMyAdmin after the Mission and Research base migrations.
-- This standalone migration is safe to run after the final-category migration
-- as well; it gives Mission Management and Secret mission access a shared,
-- explicit lock flag.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS requires_research_unlock TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled;

-- Preserve any classified mission gates already authored before the checkbox
-- existed, so they remain hidden until their matching research is unlocked.
UPDATE game_mission_definitions mission
JOIN game_research_nodes node ON node.target_mission_definition_id = mission.id
SET mission.requires_research_unlock = 1
WHERE node.effect_type = 'secret_mission';
