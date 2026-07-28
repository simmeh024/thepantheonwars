-- Contested Overlord contracts
-- Run once in phpMyAdmin (cPanel -> phpMyAdmin -> `pantheonwars` -> SQL tab)
-- after deploying the matching application files.
--
-- A contested contract remains an ordinary daily Overlord contract, but can
-- dispatch an opposing recovery team. Definition fields describe the offer an
-- administrator authors; the player-mission snapshot fields make an already
-- launched race immutable if the definition is edited later.
--
-- Every alteration is idempotent. Defaults leave all current contracts and
-- historical runs unchanged: only a contract explicitly marked contested
-- exposes the approach selection or receives a rival outcome.

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS is_contested TINYINT(1) NOT NULL DEFAULT 0 AFTER overlord_id;

ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS rival_faction_name VARCHAR(100) NULL AFTER is_contested;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS is_contested TINYINT(1) NOT NULL DEFAULT 0 AFTER reputation_reward;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS rival_faction_name VARCHAR(100) NULL AFTER is_contested;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS rival_approach VARCHAR(20) NULL AFTER rival_faction_name;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS rival_completes_at DATETIME NULL AFTER rival_approach;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS rival_outcome VARCHAR(32) NULL AFTER rival_completes_at;

ALTER TABLE game_player_missions
  ADD COLUMN IF NOT EXISTS rival_bonus_credits INT UNSIGNED NOT NULL DEFAULT 0 AFTER rival_outcome;

-- The player-facing command view reads active contested runs beside the
-- existing (user_id, status, completes_at) index, so no new hot-path index is
-- needed. This small index serves future reporting/admin filters by outcome.
ALTER TABLE game_player_missions
  ADD INDEX IF NOT EXISTS idx_game_player_mission_rival_outcome (is_contested, rival_outcome);
