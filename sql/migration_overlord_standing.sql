-- Overlord standing: a per-player, per-Overlord loyalty track fed by completed
-- daily contracts. Until this migration runs, pw_mission_overlord_standing_ready()
-- reports false and every read and write path behaves exactly as before, so
-- deploy order is not load-bearing.
--
-- Standing is deliberately stored per Overlord rather than as one column on
-- users: the affinity a player carries today is not the only one they may ever
-- have held, and a future patron switch must not silently overwrite the record
-- of the service already given.
--
-- Every statement is idempotent, so a run that fails partway can be repeated
-- from the top.

CREATE TABLE IF NOT EXISTS game_player_overlord_standing (
  user_id INT UNSIGNED NOT NULL,
  overlord_id INT UNSIGNED NOT NULL,
  points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, overlord_id),
  KEY idx_player_overlord_standing_overlord (overlord_id),
  CONSTRAINT fk_player_overlord_standing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_overlord_standing_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What one successful completion of this contract is worth in standing. Zero
-- by default, so every contract authored before this migration keeps paying
-- exactly what it paid: an administrator opts each one in deliberately.
ALTER TABLE game_mission_definitions
  ADD COLUMN IF NOT EXISTS overlord_standing_reward SMALLINT UNSIGNED NOT NULL DEFAULT 0;
