-- migration_forum_enhancements.sql
-- Batch of forum improvements: per-board accent color, edit attribution
-- (including moderator-edited attribution), server-synced unread tracking,
-- and forum-wide search. Run by hand via phpMyAdmin's SQL tab, then folded
-- into sql/schema.sql per project convention.

-- ------------------------------------------------------------------
-- Per-board accent color (Forum Control). Default matches the existing
-- fixed --purple-bright token so every board looks unchanged until an
-- admin customizes it.
-- ------------------------------------------------------------------
ALTER TABLE forum_boards
  ADD COLUMN accent_color VARCHAR(20) NOT NULL DEFAULT '#a279ec' AFTER icon_key;

-- ------------------------------------------------------------------
-- Edit attribution. edited_at already existed and rendered a plain
-- "(edited)" marker; edited_by records who made the edit so the UI can
-- distinguish a moderator's edit (community.edit_any is staff-only, so
-- edited_by is never the original author for topics/comments edited
-- through the existing moderation-only edit endpoints).
-- ------------------------------------------------------------------
ALTER TABLE topics
  ADD COLUMN edited_by INT UNSIGNED NULL AFTER edited_at,
  ADD CONSTRAINT fk_topics_edited_by FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE comments
  ADD COLUMN edited_by INT UNSIGNED NULL AFTER edited_at,
  ADD CONSTRAINT fk_comments_edited_by FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL;

-- ------------------------------------------------------------------
-- Server-synced unread tracking. Mirrors the existing client-side
-- localStorage shape (separate board-level and topic-level "last seen"
-- marks) 1:1, so logged-in members get a read state that survives across
-- devices/browsers while guests keep the existing localStorage-only
-- behavior unchanged.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_board_seen (
  user_id INT UNSIGNED NOT NULL,
  board_slug VARCHAR(50) NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, board_slug),
  CONSTRAINT fk_forum_board_seen_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_forum_board_seen_board FOREIGN KEY (board_slug) REFERENCES forum_boards(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_topic_seen (
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, topic_id),
  CONSTRAINT fk_forum_topic_seen_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_forum_topic_seen_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Forum-wide search. FULLTEXT works fine on InnoDB on this MariaDB
-- version (10.11) -- no engine change needed.
-- ------------------------------------------------------------------
ALTER TABLE topics ADD FULLTEXT INDEX ft_topics_title_body (title, body);
ALTER TABLE comments ADD FULLTEXT INDEX ft_comments_body (body);
