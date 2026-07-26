-- Research-Gated Rare Loot Tables
-- Run once in phpMyAdmin after the base Mission Loot Table, Loot Table Gear,
-- and Research Facility migrations. This adds the same explicit gate used by
-- Secret mission access, but applies it only to loot tables an administrator
-- has deliberately marked as rare.

ALTER TABLE game_loot_tables
  ADD COLUMN IF NOT EXISTS is_research_rare TINYINT(1) NOT NULL DEFAULT 0 AFTER description,
  ADD COLUMN IF NOT EXISTS requires_research_unlock TINYINT(1) NOT NULL DEFAULT 0 AFTER is_research_rare,
  ADD KEY IF NOT EXISTS idx_game_loot_table_research_lock (requires_research_unlock, is_enabled);

ALTER TABLE game_research_nodes
  ADD COLUMN IF NOT EXISTS target_loot_table_id INT UNSIGNED NULL AFTER target_mission_definition_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_game_research_rare_loot_table (target_loot_table_id);

-- MariaDB accepts IF NOT EXISTS for the column/key clauses above, but not for
-- ADD CONSTRAINT. This is a one-off migration, so add the FK conventionally.
ALTER TABLE game_research_nodes
  ADD CONSTRAINT fk_game_research_target_loot_table
    FOREIGN KEY (target_loot_table_id) REFERENCES game_loot_tables(id) ON DELETE SET NULL;

-- Preserve any rare-table research nodes authored during a staged deployment.
UPDATE game_loot_tables loot_table
JOIN game_research_nodes node ON node.target_loot_table_id = loot_table.id
SET loot_table.is_research_rare = 1,
    loot_table.requires_research_unlock = 1
WHERE node.effect_type = 'rare_loot_table';
