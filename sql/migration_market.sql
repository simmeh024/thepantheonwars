-- The Market
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- Administrators curate a catalogue of gear and characters, rather than
-- placing offers directly into each refresh. The server draws a weighted,
-- shared snapshot from that catalogue for each six-hour UTC window. Gear
-- windows begin at 00:00, 06:00, 12:00 and 18:00; character windows use the
-- same cadence one hour later. This makes the market consistent for every
-- player and prevents a refresh from re-rolling an offer.
--
-- Prices, rank gates, and stock are copied into rotation items. Editing a
-- catalogue row therefore schedules a future market change instead of
-- rewriting an offer a player is currently considering.

CREATE TABLE IF NOT EXISTS game_market_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_type VARCHAR(20) NOT NULL,
  loot_definition_id INT UNSIGNED NULL,
  crew_definition_id INT UNSIGNED NULL,
  credit_price INT UNSIGNED NOT NULL,
  required_reputation_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rotation_weight SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  stock_per_rotation SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_market_entry_gear (loot_definition_id),
  UNIQUE KEY uq_game_market_entry_crew (crew_definition_id),
  KEY idx_game_market_entry_rotation (offer_type, is_enabled, required_reputation_level),
  CONSTRAINT fk_game_market_entry_gear FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_market_entry_crew FOREIGN KEY (crew_definition_id) REFERENCES game_crew_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_market_rotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_type VARCHAR(20) NOT NULL,
  window_started_at DATETIME NOT NULL,
  window_ends_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_market_rotation_window (offer_type, window_started_at),
  KEY idx_game_market_rotation_active (offer_type, window_ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_market_rotation_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  market_rotation_id BIGINT UNSIGNED NOT NULL,
  market_entry_id INT UNSIGNED NOT NULL,
  credit_price INT UNSIGNED NOT NULL,
  required_reputation_level SMALLINT UNSIGNED NOT NULL,
  stock_initial SMALLINT UNSIGNED NOT NULL,
  stock_remaining SMALLINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_market_rotation_entry (market_rotation_id, market_entry_id),
  KEY idx_game_market_rotation_item_stock (market_rotation_id, stock_remaining),
  CONSTRAINT fk_game_market_rotation_item_rotation FOREIGN KEY (market_rotation_id) REFERENCES game_market_rotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_market_rotation_item_entry FOREIGN KEY (market_entry_id) REFERENCES game_market_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- This is an audit trail, not a dependency chain: definition and rotation rows
-- can be retired later without erasing a completed purchase record.
CREATE TABLE IF NOT EXISTS game_market_purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  rotation_item_id BIGINT UNSIGNED NOT NULL,
  offer_type VARCHAR(20) NOT NULL,
  loot_definition_id INT UNSIGNED NULL,
  crew_definition_id INT UNSIGNED NULL,
  item_name VARCHAR(120) NOT NULL,
  credit_price INT UNSIGNED NOT NULL,
  purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_game_market_purchase_user (user_id, purchased_at),
  KEY idx_game_market_purchase_recent (purchased_at, id),
  KEY idx_game_market_purchase_item (rotation_item_id),
  CONSTRAINT fk_game_market_purchase_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('market.view', 'View Market Control', 'Game Control'),
  ('market.manage', 'Create and manage market offers', 'Game Control')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
