-- migration_forum_categories.sql
-- Adds admin-managed categories that group forum boards on the public forum
-- index (Forum Control gains a "Board Categories" list; each board's Edit
-- Forum modal gains a Category picker). Categories are a pure display
-- grouping -- pw_can_see_board()'s per-board visibility rules are untouched.
-- Run by hand via phpMyAdmin's SQL tab, then folded into sql/schema.sql per
-- project convention.

CREATE TABLE IF NOT EXISTS forum_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nullable-add -> seed -> backfill -> constrain-NOT-NULL, so this is safe to
-- run against a forum_boards table that already has rows.
ALTER TABLE forum_boards ADD COLUMN category_id INT UNSIGNED NULL AFTER icon_key;

-- Every existing board defaults to "Main". The moderator-only board (live
-- data, not something this script can identify by slug) should be moved to
-- "Administration" by hand afterward, through its own Edit Forum modal --
-- exactly the tool this migration's feature adds.
INSERT INTO forum_categories (name, sort_order) VALUES ('Main', 1), ('Administration', 2);

UPDATE forum_boards SET category_id = (SELECT id FROM forum_categories WHERE name = 'Main' LIMIT 1)
WHERE category_id IS NULL;

ALTER TABLE forum_boards MODIFY COLUMN category_id INT UNSIGNED NOT NULL;
ALTER TABLE forum_boards ADD CONSTRAINT fk_forum_boards_category
  FOREIGN KEY (category_id) REFERENCES forum_categories(id);
