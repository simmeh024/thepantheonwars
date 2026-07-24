-- Missions V0
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. This creates the first server-authoritative expedition
-- loop: crew templates, player-owned crew, mission definitions, and mission
-- history. The tables deliberately use extensible string keys/statuses so
-- later worlds, injuries, equipment, and outcomes do not require a redesign.

CREATE TABLE IF NOT EXISTS game_crew_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  description TEXT NULL,
  role VARCHAR(40) NOT NULL,
  portrait_url VARCHAR(255) NOT NULL DEFAULT '',
  starting_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  world_affinity VARCHAR(50) NOT NULL DEFAULT 'neoh',
  is_starter TINYINT(1) NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_crew_definition_slug (slug),
  KEY idx_game_crew_definition_starter (is_starter, is_enabled),
  KEY idx_game_crew_definition_world (world_affinity, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_crew (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  crew_definition_id INT UNSIGNED NOT NULL,
  level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'available',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_player_crew_member (user_id, crew_definition_id),
  KEY idx_game_player_crew_user_status (user_id, status),
  CONSTRAINT fk_game_player_crew_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_crew_definition FOREIGN KEY (crew_definition_id) REFERENCES game_crew_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_mission_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_key VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  description TEXT NULL,
  mission_type VARCHAR(40) NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL,
  min_crew TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_crew TINYINT UNSIGNED NOT NULL DEFAULT 1,
  xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
  reputation_reward INT UNSIGNED NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_mission_definition_slug (slug),
  KEY idx_game_mission_definition_world (world_key, is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_missions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  mission_definition_id INT UNSIGNED NOT NULL,
  world_key VARCHAR(50) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL,
  completes_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  claimed_at DATETIME NULL,
  xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
  reputation_reward INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_game_player_missions_user_status (user_id, status, completes_at),
  KEY idx_game_player_missions_definition (mission_definition_id),
  CONSTRAINT fk_game_player_missions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_missions_definition FOREIGN KEY (mission_definition_id) REFERENCES game_mission_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_mission_crew (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_mission_id BIGINT UNSIGNED NOT NULL,
  player_crew_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_game_player_mission_crew_pair (player_mission_id, player_crew_id),
  KEY idx_game_player_mission_crew_member (player_crew_id),
  CONSTRAINT fk_game_player_mission_crew_mission FOREIGN KEY (player_mission_id) REFERENCES game_player_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_mission_crew_member FOREIGN KEY (player_crew_id) REFERENCES game_player_crew(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_crew_definitions
  (name, slug, description, role, portrait_url, starting_level, world_affinity, is_starter, is_enabled)
VALUES
  ('Kael Voss', 'kael-voss', 'A disciplined vanguard who treats every expedition as a promise to see the crew home.', 'Vanguard', 'images/char-kael.jpg', 1, 'neoh', 1, 1),
  ('Eidra Vale', 'eidra-vale', 'A patient pathfinder whose field notes find routes through even the most unstable districts.', 'Pathfinder', 'images/char-eidra.jpg', 1, 'neoh', 1, 1),
  ('Brann Kest', 'brann-kest', 'An engineer who can restore a dead relay with improvised tools and a stubborn refusal to quit.', 'Engineer', 'images/char-brann.jpg', 1, 'neoh', 1, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), role = VALUES(role),
  portrait_url = VALUES(portrait_url), starting_level = VALUES(starting_level),
  world_affinity = VALUES(world_affinity), is_starter = VALUES(is_starter), is_enabled = VALUES(is_enabled);

INSERT INTO game_mission_definitions
  (world_key, name, slug, description, mission_type, duration_seconds, min_crew, max_crew, xp_reward, reputation_reward, is_enabled, sort_order)
VALUES
  ('neoh', 'Signal Sweep', 'signal-sweep', 'Trace a repeating signal pulse across the upper relays before it vanishes into Neoh''s static.', 'recon', 300, 1, 1, 20, 0, 1, 10),
  ('neoh', 'Lower District Survey', 'lower-district-survey', 'Map a shifting route through the lower districts and return with a clean civilian transit record.', 'survey', 900, 1, 2, 35, 2, 1, 20),
  ('neoh', 'Abandoned Relay Recovery', 'abandoned-relay-recovery', 'Recover a dormant relay core from a sealed service tower before its remaining charge collapses.', 'salvage', 1800, 2, 2, 60, 5, 1, 30)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), mission_type = VALUES(mission_type), duration_seconds = VALUES(duration_seconds),
  min_crew = VALUES(min_crew), max_crew = VALUES(max_crew), xp_reward = VALUES(xp_reward), reputation_reward = VALUES(reputation_reward),
  is_enabled = VALUES(is_enabled), sort_order = VALUES(sort_order);

INSERT INTO permissions (`key`, label, category) VALUES
  ('missions.view', 'View Mission Control', 'Game Control'),
  ('missions.edit', 'Create and edit mission definitions and crew', 'Game Control'),
  ('missions.delete', 'Delete unused mission definitions and crew', 'Game Control'),
  ('missions.player_missions', 'View player mission diagnostics', 'Game Control')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
