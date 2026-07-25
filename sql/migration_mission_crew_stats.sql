-- Mission Crew Stats, Outcomes and Loot
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- Adds four automatically-allocated crew stats, mission success/failure, and a
-- loot table so Strength, Cunning and Science have something to act on. Every
-- statement is idempotent so a run that stops partway can be re-executed from
-- the top.
--
-- Defaults are deliberately no-change: base_success_percent starts at 100 (no
-- mission can fail until an administrator lowers it) and loot_rolls starts at 0
-- (no mission drops loot until one is configured). The seeds at the bottom then
-- opt the three starter Neoh operations in.

-- 1. Crew stats -------------------------------------------------------------
-- Stats are derived from role and level and capped at 50, but stored rather
-- than computed so later features (equipment, injuries, training) can adjust a
-- single crew member without breaking the level relationship.
ALTER TABLE game_player_crew
  ADD COLUMN IF NOT EXISTS strength SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER xp,
  ADD COLUMN IF NOT EXISTS cunning SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER strength,
  ADD COLUMN IF NOT EXISTS science SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER cunning,
  ADD COLUMN IF NOT EXISTS charisma SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER science;

-- 2. Mission outcome and loot configuration ---------------------------------
ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS base_success_percent TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER reputation_reward,
  ADD COLUMN IF NOT EXISTS loot_rolls TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER base_success_percent;

-- Records the resolved outcome so a claimed mission keeps its history. A failed
-- mission never reaches status 'claimed', so it can never advance a campaign
-- chain -- the unlock gate counts claimed runs only.
ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS success_percent SMALLINT UNSIGNED NULL AFTER reputation_reward,
  ADD COLUMN IF NOT EXISTS xp_bonus_percent INT NOT NULL DEFAULT 0 AFTER success_percent,
  ADD COLUMN IF NOT EXISTS reputation_bonus INT NOT NULL DEFAULT 0 AFTER xp_bonus_percent;

-- 3. Loot -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_loot_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description TEXT NULL,
  tier VARCHAR(20) NOT NULL DEFAULT 'common',
  world_key VARCHAR(50) NOT NULL DEFAULT 'neoh',
  drop_weight SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_loot_definition_slug (slug),
  KEY idx_game_loot_definition_pool (world_key, tier, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_loot (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  loot_definition_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  first_acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_player_loot_item (user_id, loot_definition_id),
  KEY idx_game_player_loot_user (user_id),
  CONSTRAINT fk_game_player_loot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_loot_definition FOREIGN KEY (loot_definition_id) REFERENCES game_loot_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Backfill existing crew stats from role and level ------------------------
-- Mirrors pw_missions_stats_for_level(): 2 points per level into the primary
-- stat for that role, 1 into Cunning, every stat capped at 50.
UPDATE game_player_crew pc
JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
SET pc.strength = CASE WHEN c.role = 'Vanguard' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.science  = CASE WHEN c.role = 'Engineer' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.charisma = CASE WHEN c.role = 'Pathfinder' THEN LEAST(50, pc.level * 2) ELSE 0 END,
    pc.cunning  = LEAST(50, pc.level);

-- 5. Seed a starter Neoh loot pool ------------------------------------------
INSERT INTO game_loot_definitions (name, slug, description, tier, world_key, drop_weight, is_enabled) VALUES
  ('Scrap Alloy', 'scrap-alloy', 'Stripped plating, still stamped with a district foundry mark.', 'common', 'neoh', 100, 1),
  ('Cracked Relay Core', 'cracked-relay-core', 'Spent, but the lattice inside is intact enough to read.', 'common', 'neoh', 90, 1),
  ('Signal Tape', 'signal-tape', 'A loop of intercepted traffic nobody has transcribed yet.', 'common', 'neoh', 80, 1),
  ('Sealed Supply Tin', 'sealed-supply-tin', 'Ration stock from before the storms closed the upper routes.', 'uncommon', 'neoh', 60, 1),
  ('Calibrated Optic', 'calibrated-optic', 'Survey glass ground to a standard no current workshop keeps.', 'uncommon', 'neoh', 50, 1),
  ('Overcode Fragment', 'overcode-fragment', 'A partial instruction set that should not still be executing.', 'rare', 'neoh', 25, 1),
  ('Vault Cipher Key', 'vault-cipher-key', 'Cut for a door the district maps insist was never built.', 'rare', 'neoh', 18, 1),
  ('Intact Pantheon Shard', 'intact-pantheon-shard', 'Whole, warm, and answering to nothing in the record.', 'legendary', 'neoh', 5, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), tier = VALUES(tier),
  world_key = VALUES(world_key), drop_weight = VALUES(drop_weight), is_enabled = VALUES(is_enabled);

-- 6. Opt the three starter operations into loot and real risk ---------------
UPDATE game_mission_definitions SET loot_rolls = 1, base_success_percent = 95 WHERE slug = 'signal-sweep';
UPDATE game_mission_definitions SET loot_rolls = 1, base_success_percent = 90 WHERE slug = 'lower-district-survey';
UPDATE game_mission_definitions SET loot_rolls = 2, base_success_percent = 85 WHERE slug = 'abandoned-relay-recovery';
