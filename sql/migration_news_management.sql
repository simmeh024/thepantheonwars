-- News Management
-- Run once in phpMyAdmin against rdy3i6my40b0_pantheonwars after deploying.
-- Creates the public-news store, permissions catalog entries, and imports the
-- two legacy static updates so news.html remains populated after cutover.

CREATE TABLE IF NOT EXISTS news_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  author_type ENUM('bh4','member') NOT NULL DEFAULT 'bh4',
  author_user_id INT UNSIGNED NULL,
  comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_news_slug (slug),
  KEY idx_news_published (published_at, id),
  CONSTRAINT fk_news_posts_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT INTO permissions (`key`, label, category) VALUES
  ('news.view', 'View News Management', 'Content'),
  ('news.edit', 'Create and edit news posts', 'Content'),
  ('news.delete', 'Delete news posts', 'Content')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

INSERT INTO news_posts (slug, title, body, author_type, published_at) VALUES
(
  'welcome-to-the-pantheon-wars',
  'Welcome to the Pantheon Wars',
  'This site marks the start of something that''s been years in the making: The Pantheon Wars, a fourteen-book saga about twelve god-cores, the tyrants who bind themselves to them, and a prophecy erased from every archive but the one nobody could find.\n\nThe Mindweaver''s Lie — Book One — follows Kael Veyr, a thief in the undercity of Neoh, and a heist that was never supposed to work. It''s the first thread pulled from a much larger tapestry: twelve worlds, twelve Overlords, and the Thirteenth Key that could unmake all of them.\n\nAll fourteen books now have titles and covers. Start with Book One, or get the full shape of the saga on the books page.',
  'bh4',
  '2026-07-01 12:00:00'
),
(
  'the-sound-of-twelve-worlds',
  'The Sound of Twelve Worlds',
  'The Mindweaver''s Lie doesn''t just come with a story — it comes with a soundtrack. Thirteen original compositions, one for each world in the Pantheon, now streaming as a full album.\n\nEach track was written for the world it belongs to: the drowned neon of Neoh, the smoke and iron of Cerius, the glassed ruins of Reanium. Press play before you turn the first page, and let the world arrive with sound already attached to it.\n\nMore tracks will follow as more worlds do. Subscribe below to know the moment they land.',
  'bh4',
  '2026-07-01 11:00:00'
)
ON DUPLICATE KEY UPDATE slug = VALUES(slug);
