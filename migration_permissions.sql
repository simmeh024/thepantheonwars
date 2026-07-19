-- Fine-grained roles & permissions system.
-- Run by hand via phpMyAdmin's SQL tab against the `pantheonwars` DB, in this
-- exact order (roles/permissions/role_permissions must exist and be seeded
-- BEFORE the users.role FK is added, or the ALTER TABLE will fail).

CREATE TABLE IF NOT EXISTS roles (
  slug VARCHAR(40) PRIMARY KEY,
  label VARCHAR(60) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#c7ccd6',
  is_superuser TINYINT(1) NOT NULL DEFAULT 0,
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  `key` VARCHAR(60) PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  category VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_slug VARCHAR(40) NOT NULL,
  permission_key VARCHAR(60) NOT NULL,
  PRIMARY KEY (role_slug, permission_key),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_key) REFERENCES permissions(`key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 3 builtin roles. admin is_superuser=1 -- pw_has_permission() short
-- circuits to true for it regardless of role_permissions rows, so it can
-- never be locked out by a permission-checkbox mistake in the new UI.
INSERT INTO roles (slug, label, color, is_superuser, is_builtin, sort_order) VALUES
  ('member', 'Member', '#c7ccd6', 0, 1, 1),
  ('moderator', 'Moderator', '#4caf6e', 0, 1, 2),
  ('admin', 'Admin', '#d1483f', 1, 1, 3)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Permission catalog.
INSERT INTO permissions (`key`, label, category) VALUES
  ('books.view', 'View Book Control', 'Lore'),
  ('books.edit', 'Create/edit books', 'Lore'),
  ('books.delete', 'Delete books', 'Lore'),
  ('dispatches.view', 'View Dispatch Control', 'Dispatches'),
  ('dispatches.edit', 'Edit dispatches', 'Dispatches'),
  ('dispatch_translations.view', 'View Dispatch Translations', 'Dispatches'),
  ('dispatch_translations.edit', 'Edit Dispatch Translations', 'Dispatches'),
  ('dashboards.view', 'View dashboards (Home, System Status, Audit Log, stats)', 'Dashboards'),
  ('dashboards.recheck_spacy', 'Recheck the spaCy script', 'Dashboards'),
  ('members.view', 'View Members list', 'Members'),
  ('members.edit', 'Edit member profile fields', 'Members'),
  ('members.change_role', 'Change a member''s role', 'Members'),
  ('members.ban', 'Ban / unban a member', 'Members'),
  ('members.reset_password', 'Reset a member''s password', 'Members'),
  ('members.reset_avatar', 'Reset a member''s avatar', 'Members'),
  ('members.delete', 'Delete a member account', 'Members'),
  ('topic_reports.view', 'View Topic Reports', 'Topic Reports'),
  ('topic_reports.manage', 'Resolve/reopen/lock/move reported topics', 'Topic Reports'),
  ('topic_reports.delete', 'Delete a reported topic', 'Topic Reports'),
  ('community.pin', 'Pin/unpin topics', 'Community moderation'),
  ('community.lock', 'Lock/unlock topics', 'Community moderation'),
  ('community.move', 'Move topics between boards', 'Community moderation'),
  ('community.edit_any', 'Edit any member''s topic/comment', 'Community moderation'),
  ('community.delete_any', 'Delete any member''s topic/comment', 'Community moderation'),
  ('community.post_announcements', 'Post in the Announcements board', 'Community moderation'),
  ('roles.manage', 'Manage roles & permissions', 'System'),
  ('admin_console.access', 'Access the admin console at all', 'System')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);

-- moderator's current real-world behavior, preserved exactly: Topic Reports
-- (view/manage/delete), all 6 community-moderation actions, and banning
-- (NOT deleting) members -- per the user's explicit ask.
INSERT INTO role_permissions (role_slug, permission_key) VALUES
  ('moderator', 'topic_reports.view'),
  ('moderator', 'topic_reports.manage'),
  ('moderator', 'topic_reports.delete'),
  ('moderator', 'community.pin'),
  ('moderator', 'community.lock'),
  ('moderator', 'community.move'),
  ('moderator', 'community.edit_any'),
  ('moderator', 'community.delete_any'),
  ('moderator', 'community.post_announcements'),
  ('moderator', 'members.ban'),
  ('moderator', 'admin_console.access')
ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug);

-- Convert users.role from a fixed ENUM to a free-form slug referencing the
-- new roles table, so custom roles become assignable. Safe: every existing
-- row's value is already one of member/moderator/admin (enforced by the old
-- ENUM), so the FK add cannot fail.
ALTER TABLE users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'member';
ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles(slug);
