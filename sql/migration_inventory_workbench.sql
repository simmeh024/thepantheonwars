-- Inventory Workbench: comparison support, saved organisation, low-value
-- salvage conversion and player-visible item provenance.
--
-- game_player_loot remains the quantity ledger. These two additive tables are
-- intentionally optional metadata: a deployment can precede this migration
-- without interrupting inventory, mission, Sweep or market play.
--
-- Idempotent: safe to re-run from the top.

-- One compact preference record per held item. Tags use a deliberately small
-- server-validated vocabulary (keep, contract, sweep, sell); they are labels
-- for player organisation, not a public/free-text system.
CREATE TABLE IF NOT EXISTS game_player_loot_preferences (
  user_id INT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  tag_key VARCHAR(24) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, loot_definition_id),
  KEY idx_game_player_loot_preferences_filter (user_id, is_favorite, tag_key),
  CONSTRAINT fk_game_player_loot_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_loot_preferences_item FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A log of additions and player-initiated removals. source_id is polymorphic
-- (mission, Sweep run, market rotation, recovery offer), so its readable type
-- and note are preserved instead of an incorrect single foreign key.
CREATE TABLE IF NOT EXISTS game_player_loot_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(24) NOT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  source_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  note VARCHAR(180) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_game_player_loot_history_item (user_id, loot_definition_id, created_at, id),
  CONSTRAINT fk_game_player_loot_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_loot_history_item FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
