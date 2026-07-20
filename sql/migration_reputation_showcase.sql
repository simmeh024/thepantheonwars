-- Public profile achievement showcase. Run once in phpMyAdmin after deploy.

CREATE TABLE IF NOT EXISTS user_reputation_achievement_showcase (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  achievement_key VARCHAR(40) NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reputation_showcase_member_achievement (user_id, achievement_key),
  UNIQUE KEY uq_reputation_showcase_member_position (user_id, position),
  CONSTRAINT fk_reputation_showcase_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
