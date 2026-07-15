-- News detail pages and comments
-- Run once in phpMyAdmin against rdy3i6my40b0_pantheonwars after deploying.
-- Existing News posts default to accepting comments.

ALTER TABLE news_posts
  ADD COLUMN IF NOT EXISTS comments_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER author_user_id;

CREATE TABLE IF NOT EXISTS news_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  news_post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_news_comments_post_created (news_post_id, created_at),
  KEY idx_news_comments_user_created (user_id, created_at),
  CONSTRAINT fk_news_comments_post FOREIGN KEY (news_post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
