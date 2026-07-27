-- Salvage Sweep
-- Run once in phpMyAdmin after the base Missions, Credits, Gear, Loot Table and
-- Crew Fatigue migrations. Every statement is idempotent, so a run that stops
-- partway can be re-run from the top.
--
-- One tier per reputation rank. The rank a player holds picks the tier, the
-- tier names a loot table, and the tier's own numbers set the board. Tiers are
-- authored in Admin -> Game Control -> Sweep Tiers; a rank with no tier row
-- simply has no sweep, which is what makes it safe to fill them in over time
-- rather than all at once.

CREATE TABLE IF NOT EXISTS game_sweep_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rank_number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  loot_table_id INT UNSIGNED NULL,
  grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 5,
  grid_cols TINYINT UNSIGNED NOT NULL DEFAULT 5,
  base_picks TINYINT UNSIGNED NOT NULL DEFAULT 5,
  hazard_count TINYINT UNSIGNED NOT NULL DEFAULT 4,
  cache_credits INT UNSIGNED NOT NULL DEFAULT 120,
  fatigue_cost TINYINT UNSIGNED NOT NULL DEFAULT 20,
  xp_reward SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_sweep_tier_rank (rank_number),
  KEY idx_game_sweep_tier_enabled (is_enabled, rank_number),
  CONSTRAINT fk_game_sweep_tier_loot_table FOREIGN KEY (loot_table_id)
    REFERENCES game_loot_tables(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per run. grid_seed never leaves the server: the cell layout is a
-- pure function of it, so a player who learned it would know every hazard.
-- revealed_cells is a comma-separated list of indexes, bounded by the grid
-- size (at most 64 cells), which is why it does not need a table of its own.
CREATE TABLE IF NOT EXISTS game_player_sweep_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  player_crew_id BIGINT UNSIGNED NOT NULL,
  rank_number SMALLINT UNSIGNED NOT NULL,
  loot_table_id INT UNSIGNED NULL,
  grid_rows TINYINT UNSIGNED NOT NULL,
  grid_cols TINYINT UNSIGNED NOT NULL,
  hazard_count TINYINT UNSIGNED NOT NULL,
  picks_total TINYINT UNSIGNED NOT NULL,
  picks_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hint_radius TINYINT UNSIGNED NOT NULL DEFAULT 0,
  shrug_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  shrug_used TINYINT(1) NOT NULL DEFAULT 0,
  grid_seed BIGINT UNSIGNED NOT NULL,
  revealed_cells TEXT NULL,
  cache_credits INT UNSIGNED NOT NULL DEFAULT 0,
  credits_found INT UNSIGNED NOT NULL DEFAULT 0,
  xp_reward SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  ended_reason VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  KEY idx_game_player_sweep_runs_active (user_id, status),
  KEY idx_game_player_sweep_runs_rank (user_id, rank_number, status),
  CONSTRAINT fk_game_player_sweep_run_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_sweep_run_crew FOREIGN KEY (player_crew_id)
    REFERENCES game_player_crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a run actually recovered, so a banked haul can be reported after the
-- fact and an abandoned one pays nothing.
CREATE TABLE IF NOT EXISTS game_player_sweep_finds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  cell_index TINYINT UNSIGNED NOT NULL,
  find_type VARCHAR(20) NOT NULL,
  loot_definition_id INT UNSIGNED NULL,
  crew_definition_id INT UNSIGNED NULL,
  credits INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_player_sweep_find_cell (run_id, cell_index),
  CONSTRAINT fk_game_player_sweep_find_run FOREIGN KEY (run_id)
    REFERENCES game_player_sweep_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The column is `key`, and there is no description column. Upserting on the
-- key rather than INSERT IGNORE so a re-run refreshes a changed label, which
-- is what every other permission migration in this repo does.
INSERT INTO permissions (`key`, label, category) VALUES
  ('sweep_tiers.view', 'View Sweep Tiers', 'Game'),
  ('sweep_tiers.manage', 'Manage Sweep Tiers', 'Game')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
