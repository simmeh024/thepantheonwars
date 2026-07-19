-- Reputation expansion: append-only ledger, configurable base rules,
-- scheduled/targeted multiplier events, and permanent achievements.
-- Run in phpMyAdmin after deploying the accompanying code.

CREATE TABLE IF NOT EXISTS reputation_reward_rules (
  `key` VARCHAR(40) NOT NULL PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  base_points SMALLINT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reputation_reward_rules (`key`, label, base_points, is_enabled) VALUES
  ('topic_created', 'Start a forum topic', 1, 1),
  ('comment_posted', 'Post a forum reply', 1, 1),
  ('content_liked', 'Receive a like', 2, 1),
  ('quiz_completed', 'Complete the Overlord quiz (first time)', 10, 1),
  ('book_started', 'Start a book (first time)', 3, 1),
  ('book_finished', 'Finish a book (first time)', 5, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);

CREATE TABLE IF NOT EXISTS reputation_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  multiplier TINYINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reward_keys_json TEXT NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reputation_events_window (is_enabled, starts_at, ends_at),
  CONSTRAINT fk_reputation_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reputation_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  reward_key VARCHAR(50) NOT NULL,
  label VARCHAR(140) NOT NULL,
  base_points SMALLINT NOT NULL,
  multiplier TINYINT UNSIGNED NOT NULL DEFAULT 1,
  points SMALLINT NOT NULL,
  source_type VARCHAR(40) NULL,
  source_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reputation_ledger_member (user_id, created_at),
  INDEX idx_reputation_ledger_created (created_at),
  CONSTRAINT fk_reputation_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reputation_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_reputation_achievements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  achievement_key VARCHAR(40) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_reputation_achievement (user_id, achievement_key),
  CONSTRAINT fk_user_reputation_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('reputation.adjust', 'Adjust member reputation', 'Community')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
