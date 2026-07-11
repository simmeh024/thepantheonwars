-- Forum Control: makes forum boards data-driven instead of hardcoded in
-- community.html, api/boards-summary.php, and api/topics/move.php (all
-- three previously carried their own copy of ['announcements','assembly',
-- 'offworld']). Also adds an optional per-board role restriction so a
-- board can be hidden from the public forum index and shown only to
-- specific roles (built-in or custom).
--
-- Run by hand via phpMyAdmin's SQL tab against the `pantheonwars` DB.

CREATE TABLE IF NOT EXISTS forum_boards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  icon_key VARCHAR(40) NOT NULL DEFAULT 'scroll',
  is_protected TINYINT(1) NOT NULL DEFAULT 0,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which roles can see a board when is_public = 0. Keyed by role_slug (not a
-- fixed member/moderator/admin enum) so a board can be restricted to any
-- existing role -- built-in or a custom one created via Roles & Permissions
-- -- matching this codebase's existing multi-role model (users.role + the
-- user_roles join table).
CREATE TABLE IF NOT EXISTS forum_board_roles (
  board_id INT UNSIGNED NOT NULL,
  role_slug VARCHAR(40) NOT NULL,
  PRIMARY KEY (board_id, role_slug),
  CONSTRAINT fk_forum_board_roles_board FOREIGN KEY (board_id) REFERENCES forum_boards(id) ON DELETE CASCADE,
  CONSTRAINT fk_forum_board_roles_role FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 3 boards that exist today, with slugs byte-identical to the
-- values already stored in topics.board -- no data migration needed.
-- 'announcements' is marked is_protected so the admin UI can't delete it
-- or change its slug (api/topics/create.php's community.post_announcements
-- check and community.html's is-announcement-board styling both key off
-- the literal slug 'announcements').
INSERT INTO forum_boards (slug, name, description, icon_key, is_protected, is_public, sort_order) VALUES
  ('announcements', 'Announcements', 'Important information is shared here by the author or moderators.', 'megaphone', 1, 1, 1),
  ('assembly', 'The Assembly', 'Discussions about each book.', 'scroll', 0, 1, 2),
  ('offworld', 'Offworld', 'Off-topic discussion about anything.', 'globe', 0, 1, 3)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Permission catalog additions for the new Forum Control admin section.
INSERT INTO permissions (`key`, label, category) VALUES
  ('forum_boards.view', 'View Forum Control', 'Community'),
  ('forum_boards.edit', 'Create/edit/reorder forum boards', 'Community'),
  ('forum_boards.delete', 'Delete forum boards', 'Community')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
