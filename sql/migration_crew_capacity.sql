-- Crew Capacity + Held Recruit Decisions
-- Run once in phpMyAdmin after the base Missions, Credits, and Research
-- migrations. Commands begin with eight crew berths; Research capacity
-- protocols can add more. New recruits at the cap are held for accept,
-- replacement, or a rarity-based credit sale instead of being discarded.

ALTER TABLE game_crew_definitions
  ADD COLUMN IF NOT EXISTS tier VARCHAR(20) NOT NULL DEFAULT 'common' AFTER description;

CREATE TABLE IF NOT EXISTS game_player_crew_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  crew_definition_id INT UNSIGNED NOT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'mission',
  source_id BIGINT UNSIGNED NULL,
  sale_credits INT UNSIGNED NOT NULL DEFAULT 100,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_game_player_crew_offers_pending (user_id, status, created_at),
  KEY idx_game_player_crew_offers_definition (crew_definition_id),
  CONSTRAINT fk_game_player_crew_offer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_crew_offer_definition FOREIGN KEY (crew_definition_id) REFERENCES game_crew_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
