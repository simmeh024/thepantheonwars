-- Salvage recovery contracts and Overlord blocked-tile clearance
-- Run once in phpMyAdmin after deploying the matching application files.
--
-- Recovery contracts reuse authored mission definitions. An administrator opts
-- an ordinary mission into the pool; when a Sweep collapse loses a rare, epic,
-- or legendary item, the server issues one private recovery lead at most once
-- per player per UTC day. The lost item's id is persisted rather than copied
-- into a payload, so the claim path can return exactly the object left behind.
--
-- Overlord clearance rows make an administrator-enabled four-quadrant tile
-- stable across refreshes. One server-selected quadrant collapses; two safe
-- scans are required before that daily Overlord contract may launch. Every
-- statement is idempotent.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS is_salvage_recovery_contract TINYINT(1) NOT NULL DEFAULT 0 AFTER overlord_id;

-- Existing contracts must remain launchable. A blocked tile is explicitly
-- authored per Overlord contract, rather than silently becoming mandatory for
-- every contract on the day this migration is deployed.
ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS requires_overlord_clearance TINYINT(1) NOT NULL DEFAULT 0 AFTER is_salvage_recovery_contract;

ALTER TABLE game_mission_definitions
  ADD INDEX IF NOT EXISTS idx_game_mission_salvage_recovery (is_salvage_recovery_contract, is_enabled, sort_order);

CREATE TABLE IF NOT EXISTS game_player_salvage_recovery_contracts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  source_sweep_run_id BIGINT UNSIGNED NULL,
  source_sweep_find_id BIGINT UNSIGNED NULL,
  mission_definition_id INT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NULL,
  issued_date DATE NOT NULL,
  player_mission_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'available',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_salvage_recovery_daily (user_id, issued_date),
  UNIQUE KEY uq_game_salvage_recovery_find (source_sweep_find_id),
  UNIQUE KEY uq_game_salvage_recovery_run (player_mission_id),
  KEY idx_game_salvage_recovery_open (user_id, status, issued_date),
  KEY idx_game_salvage_recovery_mission (mission_definition_id),
  CONSTRAINT fk_game_salvage_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_salvage_recovery_sweep FOREIGN KEY (source_sweep_run_id) REFERENCES game_player_sweep_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_game_salvage_recovery_find FOREIGN KEY (source_sweep_find_id) REFERENCES game_player_sweep_finds(id) ON DELETE SET NULL,
  CONSTRAINT fk_game_salvage_recovery_mission FOREIGN KEY (mission_definition_id) REFERENCES game_mission_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_game_salvage_recovery_loot FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE SET NULL,
  CONSTRAINT fk_game_salvage_recovery_player_mission FOREIGN KEY (player_mission_id) REFERENCES game_player_missions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_overlord_contract_clearances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  mission_definition_id INT UNSIGNED NOT NULL,
  issued_date DATE NOT NULL,
  collapse_index TINYINT UNSIGNED NOT NULL,
  safe_picks VARCHAR(15) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'blocked',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_overlord_clearance_daily (user_id, issued_date),
  KEY idx_game_overlord_clearance_contract (user_id, mission_definition_id, issued_date),
  CONSTRAINT fk_game_overlord_clearance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_overlord_clearance_mission FOREIGN KEY (mission_definition_id) REFERENCES game_mission_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
