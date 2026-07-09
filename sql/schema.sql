-- The Pantheon Wars — member system schema
-- Run this once in phpMyAdmin (or the cPanel MySQL Database wizard's "Execute SQL")
-- against the database you create for this site.

-- Custom roles + fine-grained permissions (see migration_permissions.sql for
-- the one-time seed data). 'admin' is_superuser=1 always passes every
-- pw_has_permission() check regardless of role_permissions rows, so no
-- combination of checkbox edits can lock every admin out. member/moderator
-- are is_builtin=1 (can't be renamed/deleted, but their permission set and
-- color ARE editable) -- everything else is a fully custom role.
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

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(30) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(50) NOT NULL,
  overlord_affinity VARCHAR(50) DEFAULT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'member',
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME DEFAULT NULL,
  last_login_ip VARCHAR(64) DEFAULT NULL,
  last_active_at DATETIME DEFAULT NULL,
  banned_at DATETIME DEFAULT NULL,
  banned_until DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_email (email),
  CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  overlord_result VARCHAR(50) NOT NULL,
  scores_json VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_id (user_id),
  CONSTRAINT fk_quiz_results_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nexus Veil forum: one row per topic (thread starter). board is one of
-- 'announcements' | 'assembly' | 'offworld' (see BOARDS in community.html).
-- is_locked/locked_at gate new replies (see api/comments/post.php); Move
-- (api/topics/move.php) just rewrites `board`; Edit (api/topics/edit.php)
-- stamps edited_at so the front-end can show an "(edited)" marker.
CREATE TABLE IF NOT EXISTS topics (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board VARCHAR(50) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  pinned_at DATETIME DEFAULT NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  locked_at DATETIME DEFAULT NULL,
  edited_at DATETIME DEFAULT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_board (board),
  CONSTRAINT fk_topics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Threaded replies within a topic (max depth 2 -- see api/comments/post.php).
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at DATETIME DEFAULT NULL,
  KEY idx_topic_id (topic_id),
  KEY idx_parent_id (parent_id),
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispatch_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sha VARCHAR(40) NOT NULL,
  subject VARCHAR(500) NOT NULL,
  body TEXT DEFAULT NULL,
  tag ENUM('feature','fix','update') NOT NULL DEFAULT 'update',
  author VARCHAR(100) NOT NULL,
  committed_at DATETIME NOT NULL,
  url VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sha (sha),
  KEY idx_committed_at (committed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispatch_reactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  reaction_type ENUM('like','dislike') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_user (dispatch_id, user_id),
  KEY idx_dispatch_id (dispatch_id),
  CONSTRAINT fk_dispatch_reactions_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dispatch_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repo_language_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  captured_at DATETIME NOT NULL,
  total_bytes BIGINT UNSIGNED NOT NULL,
  languages_json TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_captured_at (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports raised by members against a topic or a reply, reviewed by
-- moderators/admins on the admin console's Topic Reports page. resolution
-- is filled in when a mod closes the report; resolved_by/resolved_at record
-- who closed it and when. Quick actions taken from that page (lock/move the
-- topic, delete the topic or comment) are separate operations logged to
-- admin_activity_log -- they don't automatically close the report.
CREATE TABLE IF NOT EXISTS content_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('topic','comment') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  reporter_user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  resolution VARCHAR(1000) DEFAULT NULL,
  resolved_by INT UNSIGNED DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_target (target_type, target_id),
  CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_reports_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_likes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('topic','comment') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_message_like (target_type, target_id, user_id),
  KEY idx_target (target_type, target_id),
  CONSTRAINT fk_message_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispatch_translations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  sha VARCHAR(40) NOT NULL,
  translation TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_id (dispatch_id),
  KEY idx_sha (sha),
  CONSTRAINT fk_dispatch_translations_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(100) NOT NULL,
  action VARCHAR(40) NOT NULL,
  description TEXT NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_at (created_at),
  CONSTRAINT fk_admin_activity_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(64) PRIMARY KEY,
  value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpu_load_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  load1 DECIMAL(6,2) NOT NULL,
  load5 DECIMAL(6,2) NOT NULL,
  load15 DECIMAL(6,2) NOT NULL,
  recorded_at DATETIME NOT NULL,
  KEY idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every login attempt (success or failure), backing api/login.php's
-- IP-based throttle and doubling as an audit trail for non-admin accounts.
-- No dedicated cron -- pruned opportunistically to ~90 days by
-- pw_log_login_attempt() in api/helpers.php on ~2% of inserts.
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(64) NOT NULL,
  identifier VARCHAR(255) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_created (ip_address, created_at),
  KEY idx_identifier_created (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
