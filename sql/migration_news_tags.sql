-- Adds reusable tags to an already-deployed News Management feature.
-- Skip this if you run the current migration_news_management.sql for the
-- first time, as that complete migration now includes these two tables.

CREATE TABLE IF NOT EXISTS news_tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  label VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_news_tag_slug (slug),
  UNIQUE KEY uniq_news_tag_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_post_tags (
  news_post_id INT UNSIGNED NOT NULL,
  news_tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (news_post_id, news_tag_id),
  KEY idx_news_post_tags_tag (news_tag_id, news_post_id),
  CONSTRAINT fk_news_post_tags_post FOREIGN KEY (news_post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_post_tags_tag FOREIGN KEY (news_tag_id) REFERENCES news_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
