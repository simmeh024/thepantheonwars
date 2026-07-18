-- Dispatch Composer
-- Run once in phpMyAdmin against the pantheonwars DB after deploying.
-- Creates the Composer draft table, the dispatch-attachment table, and the
-- five permission catalogue entries. A Composer post's slug is only a
-- working preview value scoped to this table; the real published slug is
-- resolved against news_posts separately at publish time (pw_news_unique_slug),
-- so the two can never collide with each other in confusing ways.

CREATE TABLE IF NOT EXISTS dispatch_composer_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL DEFAULT '',
  slug VARCHAR(255) NULL,
  excerpt TEXT NULL,
  body MEDIUMTEXT NULL,
  featured_image_url VARCHAR(500) NULL,
  status ENUM('draft', 'ready', 'published', 'archived') NOT NULL DEFAULT 'draft',
  news_post_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NOT NULL,
  published_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  UNIQUE KEY uq_dispatch_composer_slug (slug),
  UNIQUE KEY uq_dispatch_composer_news_post (news_post_id),
  KEY idx_dispatch_composer_status (status),
  CONSTRAINT fk_dispatch_composer_news_post FOREIGN KEY (news_post_id) REFERENCES news_posts(id) ON DELETE SET NULL,
  CONSTRAINT fk_dispatch_composer_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_dispatch_composer_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT fk_dispatch_composer_published_by FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which approved dispatches were used as source/reference material for a
-- draft. admin_note is a private writing aid ("mention the mobile impact",
-- "combine with the notification update") and is never published.
CREATE TABLE IF NOT EXISTS dispatch_composer_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  composer_post_id INT UNSIGNED NOT NULL,
  dispatch_id INT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  admin_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_composer_dispatch (composer_post_id, dispatch_id),
  KEY idx_composer_items_order (composer_post_id, sort_order),
  KEY idx_composer_items_dispatch (dispatch_id),
  CONSTRAINT fk_composer_items_post FOREIGN KEY (composer_post_id) REFERENCES dispatch_composer_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_composer_items_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('dispatch_composer.view', 'View Dispatch Composer', 'Dispatch Composer'),
  ('dispatch_composer.create', 'Create Composer drafts', 'Dispatch Composer'),
  ('dispatch_composer.edit', 'Edit Composer drafts and attach dispatches', 'Dispatch Composer'),
  ('dispatch_composer.publish', 'Publish Composer articles to News', 'Dispatch Composer'),
  ('dispatch_composer.archive', 'Archive Composer drafts', 'Dispatch Composer')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
