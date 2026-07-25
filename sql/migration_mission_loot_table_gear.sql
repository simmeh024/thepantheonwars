-- Mission Loot Table Gear
--
-- Run manually in phpMyAdmin after sql/migration_mission_gear.sql and
-- sql/migration_mission_loot_tables.sql. Loot-table rows already present are
-- character rows and remain intact; a nullable second source column lets gear
-- join them without translating or deleting any existing configuration.
--
-- MariaDB 10.11 on the production host supports IF NOT EXISTS on these ALTER
-- clauses, making the record safe to re-run after a partially completed admin
-- deployment.

ALTER TABLE game_loot_table_entries
  ADD COLUMN IF NOT EXISTS loot_definition_id INT UNSIGNED NULL AFTER crew_definition_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_game_loot_table_entry_gear (loot_table_id, loot_definition_id),
  ADD KEY IF NOT EXISTS idx_game_loot_table_entry_gear (loot_definition_id),
  ADD CONSTRAINT fk_game_loot_table_entry_gear
    FOREIGN KEY IF NOT EXISTS (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE;
