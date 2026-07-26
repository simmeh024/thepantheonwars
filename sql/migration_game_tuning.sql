-- Game Tuning (Game Control -> Game Tuning)
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> `pantheonwars` -> SQL tab).
-- Every statement is idempotent, so a run that stops partway can be repeated
-- from the top.
--
-- A read-only balance simulator. It writes nothing to any game table: the only
-- thing it stores is a saved scenario, which is a set of inputs to re-run, not
-- a result and not player state.
--
-- Its own permission rather than reusing missions.view, because the page puts
-- the entire reward economy -- every item's power, every mission's pay rate,
-- every research effect -- on one screen. That is a different disclosure from
-- being able to edit one mission.

INSERT INTO permissions (`key`, label, category) VALUES
  ('game_tuning.view', 'View Game Tuning', 'Game')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- Saved scenarios: crew member, loadout, research state and the contracts being
-- compared. Per-administrator rather than global, so one person's working
-- comparison does not overwrite another's.
--
-- The configuration is JSON rather than a set of columns because it is an
-- opaque input bundle that only this page reads, and its shape will change as
-- the simulator grows. Nothing joins on its contents. Every id inside it is
-- re-validated against the live catalogue when the scenario is loaded, so a
-- scenario naming an item that has since been deleted degrades to that slot
-- being empty rather than to an error.
CREATE TABLE IF NOT EXISTS game_tuning_scenarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  config_json TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_tuning_scenario_name (user_id, name),
  KEY idx_game_tuning_scenario_user (user_id, updated_at),
  CONSTRAINT fk_game_tuning_scenario_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
