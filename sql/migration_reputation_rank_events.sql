-- Sitewide reputation rank-up celebration queue.
-- Run in phpMyAdmin after deploying the accompanying application code.
-- Rows are created only when a player crosses a rank from this point forward,
-- so established members are never shown a retroactive celebration.

CREATE TABLE IF NOT EXISTS user_reputation_rank_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reputation_level_id INT UNSIGNED NOT NULL,
  reputation_points INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL DEFAULT NULL,
  KEY idx_user_reputation_rank_events_pending (user_id, delivered_at, id),
  CONSTRAINT fk_user_reputation_rank_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_reputation_rank_events_level FOREIGN KEY (reputation_level_id) REFERENCES reputation_levels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
