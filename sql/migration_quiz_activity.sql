-- Quiz Activity: aggregate starts, completions and final Overlord outcomes
-- for both guests and signed-in members. Browser UUIDs are hashed before
-- storage; answers and raw visitor ids are intentionally never written here.
CREATE TABLE IF NOT EXISTS quiz_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_token_hash CHAR(64) NOT NULL,
  visitor_token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL DEFAULT NULL,
  overlord_result VARCHAR(100) NULL,
  UNIQUE KEY uq_quiz_activity_attempt (attempt_token_hash),
  KEY idx_quiz_activity_started (started_at),
  KEY idx_quiz_activity_completed (completed_at),
  KEY idx_quiz_activity_result (overlord_result),
  KEY idx_quiz_activity_user (user_id),
  CONSTRAINT fk_quiz_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
