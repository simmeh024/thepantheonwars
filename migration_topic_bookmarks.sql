-- migration_topic_bookmarks.sql
-- Per-user "save for later" bookmarks on forum topics, backing the new
-- Bookmarks tab + the kebab menu's Bookmark/Remove Bookmark item.
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per project convention.

CREATE TABLE topic_bookmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_topic (user_id, topic_id),
  CONSTRAINT fk_topic_bookmarks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_topic_bookmarks_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
