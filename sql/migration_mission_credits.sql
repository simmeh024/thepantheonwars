-- Mission Credits
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- Adds the expedition currency. Credits are a player-level balance rather than
-- a users column: pw_current_user() runs on every authenticated request
-- site-wide, so a wallet column there would put a game balance in the hot path
-- of the whole site and make a pending migration a site-wide fatal rather than
-- one missing figure on one page.
--
-- Every statement is idempotent so a run that stops partway can be re-executed
-- from the top. Defaults are no-change: credit_reward starts at 0, so no
-- mission pays anything until an administrator sets a figure. The seeds at the
-- bottom then opt the three starter Neoh operations in.

-- 1. The wallet -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_player_wallet (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  credits BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_game_player_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. What a mission pays ----------------------------------------------------
ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS credit_reward INT UNSIGNED NOT NULL DEFAULT 0 AFTER reputation_reward;

-- Recorded per run so mission history keeps the figure that was actually paid,
-- even after an administrator later re-prices the operation.
ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS credits_awarded INT UNSIGNED NOT NULL DEFAULT 0 AFTER reputation_reward;

-- 3. Price the three starter operations --------------------------------------
-- Roughly in line with their risk: the longer, more dangerous recovery pays
-- most.
UPDATE game_mission_definitions SET credit_reward = 120 WHERE slug = 'signal-sweep';
UPDATE game_mission_definitions SET credit_reward = 200 WHERE slug = 'lower-district-survey';
UPDATE game_mission_definitions SET credit_reward = 340 WHERE slug = 'abandoned-relay-recovery';
