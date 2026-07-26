-- Research Facility
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. Research nodes are reusable administrator-authored
-- protocols; player ownership and every cost are recorded separately.

CREATE TABLE IF NOT EXISTS game_research_nodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description TEXT NULL,
  image_url VARCHAR(255) NOT NULL DEFAULT '',
  effect_type VARCHAR(32) NOT NULL,
  effect_value DECIMAL(7,2) NOT NULL DEFAULT 0,
  target_mission_definition_id INT UNSIGNED NULL,
  required_reputation_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  credit_cost INT UNSIGNED NOT NULL DEFAULT 0,
  salvage_loot_definition_id INT UNSIGNED NULL,
  salvage_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  canvas_x SMALLINT UNSIGNED NOT NULL DEFAULT 80,
  canvas_y SMALLINT UNSIGNED NOT NULL DEFAULT 80,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_research_node_slug (slug),
  UNIQUE KEY uq_game_research_secret_mission (target_mission_definition_id),
  KEY idx_game_research_nodes_enabled (is_enabled, sort_order),
  KEY idx_game_research_salvage (salvage_loot_definition_id),
  CONSTRAINT fk_game_research_target_mission FOREIGN KEY (target_mission_definition_id) REFERENCES game_mission_definitions(id) ON DELETE SET NULL,
  CONSTRAINT fk_game_research_salvage FOREIGN KEY (salvage_loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_research_prerequisites (
  research_node_id INT UNSIGNED NOT NULL,
  prerequisite_node_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (research_node_id, prerequisite_node_id),
  KEY idx_game_research_prerequisite_reverse (prerequisite_node_id),
  CONSTRAINT fk_game_research_prerequisite_node FOREIGN KEY (research_node_id) REFERENCES game_research_nodes(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_research_prerequisite_required FOREIGN KEY (prerequisite_node_id) REFERENCES game_research_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_research (
  user_id INT UNSIGNED NOT NULL,
  research_node_id INT UNSIGNED NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, research_node_id),
  KEY idx_game_player_research_node (research_node_id),
  CONSTRAINT fk_game_player_research_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_research_node FOREIGN KEY (research_node_id) REFERENCES game_research_nodes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A player can unlock a faster Market cadence. Rotations remain shared within
-- the same cadence cohort, so availability and stock are still server-owned;
-- the cadence is part of the unique key so it never collides with the base
-- six-hour rotation at a matching timestamp.
ALTER TABLE game_market_rotations
  ADD COLUMN IF NOT EXISTS research_refresh_percent DECIMAL(7,2) NOT NULL DEFAULT 0 AFTER offer_type,
  DROP INDEX uq_game_market_rotation_window,
  ADD UNIQUE KEY uq_game_market_rotation_window (offer_type, research_refresh_percent, window_started_at),
  ADD KEY idx_game_market_rotation_scope_active (offer_type, research_refresh_percent, window_ends_at);

INSERT INTO permissions (`key`, label, category) VALUES
  ('research.view', 'View Research Management', 'Game Control'),
  ('research.manage', 'Create and manage research unlocks', 'Game Control')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
