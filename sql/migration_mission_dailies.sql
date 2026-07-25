-- Daily mission objectives
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files.
--
-- One objective is shown per player per UTC day, chosen deterministically from
-- a fixed catalogue in PHP so a refresh can never reroll it. Everything resets
-- at UTC midnight, matching every other daily boundary in this project
-- (weather forecasts, page-view rollups, the visitor heatmap).
--
-- Progress is counted forward rather than derived on read. Two of the three
-- objectives could be reconstructed from game_player_missions, but a crew
-- level-up leaves no record anywhere -- levels are recomputed from total XP, so
-- there is nothing to count after the fact. One counting mechanism for all
-- three keeps the objectives consistent with each other.

-- 1. Per-metric daily counters ----------------------------------------------
-- Keyed by metric rather than by objective, so the same counter serves the
-- objective on show today and any objective added later that measures the same
-- thing. Rows are only written for a metric the player has actually moved.
CREATE TABLE IF NOT EXISTS game_player_daily_progress (
  user_id INT UNSIGNED NOT NULL,
  stat_date DATE NOT NULL,
  metric_key VARCHAR(40) NOT NULL,
  progress INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, stat_date, metric_key),
  KEY idx_game_player_daily_progress_date (stat_date),
  CONSTRAINT fk_game_player_daily_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Reward claims -----------------------------------------------------------
-- The primary key is the whole guard against claiming twice: an INSERT that
-- collides has already been paid, whatever raced it.
CREATE TABLE IF NOT EXISTS game_player_daily_claims (
  user_id INT UNSIGNED NOT NULL,
  stat_date DATE NOT NULL,
  daily_key VARCHAR(40) NOT NULL,
  reward_type VARCHAR(20) NOT NULL,
  reward_amount INT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, stat_date, daily_key),
  KEY idx_game_player_daily_claims_date (stat_date),
  CONSTRAINT fk_game_player_daily_claims_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
