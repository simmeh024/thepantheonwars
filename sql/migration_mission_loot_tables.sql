-- Mission Loot Tables
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- A loot table is a reusable named group of possible rewards. Missions attach
-- tables rather than owning their own reward lists, so one table can be shared
-- across a whole campaign and re-tuned in a single place.
--
-- Two independent chances, which is the model the admin screens present:
--   * the mission -> table link carries the chance the table is opened at all
--     on a successful run;
--   * each entry inside the table carries its own chance of dropping.
-- Entries are therefore independent rolls, not a weighted pick -- a table can
-- award several characters at once, or none.
--
-- Only characters (crew definitions) are supported as loot for now.
-- entry_type exists so items or credits can be added later without another
-- structural migration; every current row is 'crew'.
--
-- Every statement is idempotent so a run that stops partway can be re-executed
-- from the top. Nothing changes for existing missions: a mission with no loot
-- table attached behaves exactly as before.

-- 1. The tables themselves ---------------------------------------------------
CREATE TABLE IF NOT EXISTS game_loot_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_loot_table_slug (slug),
  KEY idx_game_loot_table_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. What is inside a table --------------------------------------------------
-- chance_percent is DECIMAL(6,3) rather than an integer so a genuinely rare
-- character can be set to 0.5% or 0.05% -- at integer precision the rarest a
-- drop could ever be is 1 in 100, which is not rare at all for a character.
--
-- The unique key keeps one row per character per table: two rows for the same
-- character would roll twice and quietly double its real chance.
CREATE TABLE IF NOT EXISTS game_loot_table_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loot_table_id INT UNSIGNED NOT NULL,
  entry_type VARCHAR(20) NOT NULL DEFAULT 'crew',
  crew_definition_id INT UNSIGNED NULL,
  chance_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_loot_table_entry_crew (loot_table_id, crew_definition_id),
  KEY idx_game_loot_table_entry_order (loot_table_id, sort_order, id),
  CONSTRAINT fk_game_loot_table_entry_table FOREIGN KEY (loot_table_id) REFERENCES game_loot_tables(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_loot_table_entry_crew FOREIGN KEY (crew_definition_id) REFERENCES game_crew_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Which tables a mission opens -------------------------------------------
CREATE TABLE IF NOT EXISTS game_mission_loot_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_definition_id INT UNSIGNED NOT NULL,
  loot_table_id INT UNSIGNED NOT NULL,
  chance_percent DECIMAL(6,3) NOT NULL DEFAULT 100.000,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_mission_loot_table (mission_definition_id, loot_table_id),
  KEY idx_game_mission_loot_table_order (mission_definition_id, sort_order, id),
  CONSTRAINT fk_game_mission_loot_table_mission FOREIGN KEY (mission_definition_id) REFERENCES game_mission_definitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_mission_loot_table_table FOREIGN KEY (loot_table_id) REFERENCES game_loot_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Permissions -------------------------------------------------------------
-- Separate from missions.view/missions.edit so loot balancing can be delegated
-- without also handing over mission timing, crew and rewards. An administrator
-- role is unaffected either way -- it holds the '*' superuser bypass.
INSERT INTO permissions (`key`, label, category) VALUES
  ('loot_tables.view', 'View Loot Table Management', 'Game Control'),
  ('loot_tables.edit', 'Create and edit loot tables', 'Game Control')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
