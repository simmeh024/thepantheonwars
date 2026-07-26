-- Daily Overlord contracts
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> `pantheonwars` -> SQL tab).
-- Every statement is idempotent, so a run that stops partway can be repeated
-- from the top.
--
-- An Overlord contract is an ordinary mission definition with an Overlord
-- attached. That is deliberate: reusing game_mission_definitions means the
-- whole existing pipeline -- crew assignment, fatigue, weather, the success
-- roll, loot, the debrief -- applies to a contract with no new code, where a
-- parallel contract table would have needed all of it written twice and would
-- have drifted from the mission rules the first time either changed.
--
-- A mission with overlord_id set is withdrawn from the ordinary mission board
-- and is only ever offered as the daily contract, to players whose quiz
-- affinity matches that Overlord and who have reached the required reputation
-- rank. ON DELETE SET NULL rather than CASCADE: removing an Overlord must not
-- delete authored mission content, it should return those contracts to being
-- ordinary unassigned missions for an administrator to re-file.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS overlord_id INT UNSIGNED NULL AFTER world_key;

-- Guarded separately: ADD CONSTRAINT has no IF NOT EXISTS in MariaDB, so a
-- re-run would fail on the constraint even though the column already exists.
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'game_mission_definitions'
    AND CONSTRAINT_NAME = 'fk_game_mission_overlord'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE game_mission_definitions ADD CONSTRAINT fk_game_mission_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Leads with overlord_id so it serves the daily-contract pool lookup, which
-- always filters on that column first.
ALTER TABLE game_mission_definitions
  ADD INDEX IF NOT EXISTS idx_game_mission_overlord (overlord_id, is_enabled, sort_order);
