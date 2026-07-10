-- Adds support for a member holding additional ("other") roles beyond their
-- single main role (users.role). Main role still drives the display color
-- and rank badge shown to visitors; rows in this table only add extra
-- permission grants on top of that, via the same role_permissions table
-- every other role already uses. Run by hand via phpMyAdmin's SQL tab
-- against the `pantheonwars` database, then keep this file in the repo
-- alongside sql/schema.sql (which already has the same CREATE TABLE folded
-- in) per this project's migration convention.

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_slug VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, role_slug),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
