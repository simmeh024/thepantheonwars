-- Adds the public-site notification system: a single notifications table
-- covering all four trigger types (like, mention, quote, report_resolved),
-- plus a real relational link for the previously text-only Quote feature.
-- Run by hand via phpMyAdmin's SQL tab against the `pantheonwars` database.

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('like','mention','quote','report_resolved') NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  topic_id INT UNSIGNED NULL,
  comment_id INT UNSIGNED NULL,
  report_id INT UNSIGNED NULL,
  excerpt VARCHAR(200) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_created (user_id, created_at),
  KEY idx_user_unread (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_notifications_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_report FOREIGN KEY (report_id) REFERENCES content_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE comments
  ADD COLUMN quoted_comment_id INT UNSIGNED NULL AFTER parent_id,
  ADD CONSTRAINT fk_comments_quoted FOREIGN KEY (quoted_comment_id) REFERENCES comments(id) ON DELETE SET NULL;
