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

-- A member's "other roles" -- additional roles beyond their single main
-- role (users.role) that add extra permission grants without changing
-- which role's color/rank badge is shown publicly. See
-- migration_user_roles.sql for the standalone one-off.
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_slug VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, role_slug),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE
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

-- Forum boards (admin-managed via the "Forum Control" section). Board
-- metadata used to be hardcoded in community.html/api/boards-summary.php/
-- api/topics/move.php -- this table is now the single source of truth.
-- icon_key is a closed enum resolved to SVG by a fixed lookup on both the
-- public and admin side, never raw stored SVG (avoids a stored-XSS surface
-- on the public forum page).
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

-- Which roles can see a board when is_public = 0 (see pw_can_see_board() in
-- api/helpers.php). Keyed by role_slug so a hidden board can be scoped to
-- any existing role, built-in or custom.
CREATE TABLE IF NOT EXISTS forum_board_roles (
  board_id INT UNSIGNED NOT NULL,
  role_slug VARCHAR(40) NOT NULL,
  PRIMARY KEY (board_id, role_slug),
  CONSTRAINT fk_forum_board_roles_board FOREIGN KEY (board_id) REFERENCES forum_boards(id) ON DELETE CASCADE,
  CONSTRAINT fk_forum_board_roles_role FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nexus Veil forum: one row per topic (thread starter). board is a slug
-- referencing forum_boards.slug (not a real FK -- kept as free text so a
-- board can be deleted/renamed independently without touching every topic
-- row; api/topics/create.php and api/topics/move.php validate against
-- forum_boards at request time instead).
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
  KEY idx_board_active_created (board, is_deleted, created_at),
  KEY idx_user_active_created (user_id, is_deleted, created_at),
  CONSTRAINT fk_topics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Threaded replies within a topic (max depth 2 -- see api/comments/post.php).
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED DEFAULT NULL,
  quoted_comment_id INT UNSIGNED DEFAULT NULL, -- relational link for the Quote button (see community.html); powers the "quote" notification type
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at DATETIME DEFAULT NULL,
  KEY idx_topic_id (topic_id),
  KEY idx_topic_active_created (topic_id, is_deleted, created_at),
  KEY idx_user_active_created (user_id, is_deleted, created_at),
  KEY idx_parent_id (parent_id),
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_quoted FOREIGN KEY (quoted_comment_id) REFERENCES comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user "save for later" bookmarks on topics (Bookmarks tab + kebab menu).
CREATE TABLE IF NOT EXISTS topic_bookmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_topic (user_id, topic_id),
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_topic_bookmarks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_topic_bookmarks_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
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

-- One row per calendar day, backing the admin Home page's "Total Lines of
-- Code" tile and its "+N today" delta (api/admin/loc-stats.php).
CREATE TABLE IF NOT EXISTS loc_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  captured_at DATE NOT NULL UNIQUE,
  total_lines INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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

-- Raw per-page-view log backing the "Visitor Statistics" admin page.
-- Pruned to a 90-day rolling window by api/cron/rollup-page-views.php,
-- which also rolls each finished day up into page_view_daily_stats below.
CREATE TABLE IF NOT EXISTS page_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  referrer_host VARCHAR(255) NULL,
  visitor_id CHAR(36) NOT NULL,
  user_id INT UNSIGNED NULL,
  ip_address VARCHAR(64) NULL,
  country_code CHAR(2) NULL,
  country_name VARCHAR(100) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_at (created_at),
  KEY idx_visitor_created_id (visitor_id, created_at, id),
  CONSTRAINT fk_page_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-IP cache of resolved country (via a free ip-api.com lookup in
-- pw_resolve_country(), api/helpers.php) so the same visitor's IP is only
-- looked up once ever, not on every page view. Keyed on the raw IP string
-- (same format pw_client_ip() returns); never expired/refreshed since an
-- IP's country essentially never changes for the lifetime of this cache's
-- usefulness.
CREATE TABLE IF NOT EXISTS ip_country_cache (
  ip_address VARCHAR(64) PRIMARY KEY,
  country_code CHAR(2) NULL,
  country_name VARCHAR(100) NULL,
  resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent one-row-per-day rollup of page_views, so the "visits over
-- time" chart can show long-range trends without the raw table above
-- growing unbounded.
-- total_views_excl_admin/unique_visitors_excl_admin/member_views_excl_admin
-- back the Visitor Statistics page's "Include admin traffic" setting
-- (default off): a parallel set of totals computed the same way but with
-- any page view attributed to a superuser-role user removed. guest_views
-- needs no excl_admin twin -- a superuser must be logged in to be
-- identified as one, so admin views are always a subset of member_views.
CREATE TABLE IF NOT EXISTS page_view_daily_stats (
  stat_date DATE PRIMARY KEY,
  total_views INT UNSIGNED NOT NULL DEFAULT 0,
  unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
  member_views INT UNSIGNED NOT NULL DEFAULT 0,
  guest_views INT UNSIGNED NOT NULL DEFAULT 0,
  total_views_excl_admin INT UNSIGNED NOT NULL DEFAULT 0,
  unique_visitors_excl_admin INT UNSIGNED NOT NULL DEFAULT 0,
  member_views_excl_admin INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public-site notification system: one row per notification, covering all
-- four trigger types (like, mention, quote, report_resolved). See
-- api/messages/like.php, api/topics/create.php, api/comments/post.php, and
-- api/admin/topic-reports/resolve.php for the write sites, and
-- api/notifications/*.php for reads.
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('like','mention','quote','report_resolved','world_available') NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  topic_id INT UNSIGNED NULL,
  comment_id INT UNSIGNED NULL,
  report_id INT UNSIGNED NULL,
  world_id INT UNSIGNED NULL,
  excerpt VARCHAR(200) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_created (user_id, created_at),
  KEY idx_user_unread (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_notifications_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_report FOREIGN KEY (report_id) REFERENCES content_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user notification type opt-out flags, edited on profile.html's
-- Notification Settings tab. One row per user who has ever saved a
-- preference; a missing row means every type is enabled (see
-- pw_notifications_enabled() in api/helpers.php), so existing users don't
-- need a backfill.
CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT UNSIGNED PRIMARY KEY,
  notif_like TINYINT(1) NOT NULL DEFAULT 1,
  notif_mention TINYINT(1) NOT NULL DEFAULT 1,
  notif_quote TINYINT(1) NOT NULL DEFAULT 1,
  notif_report_resolved TINYINT(1) NOT NULL DEFAULT 1,
  notif_world_available TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual backup log for System Status's "Last Backup" row -- cPanel's own
-- automated account backups are disabled on this hosting account (server-
-- admin-level setting, not toggleable from this account), so there's no
-- real automated timestamp to check. Stamped whenever an admin clicks
-- "Log Backup Now" after actually performing one (e.g. a phpMyAdmin
-- export).
CREATE TABLE IF NOT EXISTS backup_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  logged_by INT UNSIGNED NULL,
  CONSTRAINT fk_backup_log_user FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- World Control: powers the public worlds.html page (replaces what used to
-- be hand-authored HTML) plus the admin CRUD. A world's rich interactive
-- detail section (cross-section map + accordion of layers/districts) is
-- only populated for status = 'available' worlds.
CREATE TABLE IF NOT EXISTS worlds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  tagline VARCHAR(200) NOT NULL DEFAULT '',
  card_blurb VARCHAR(300) NOT NULL DEFAULT '',
  thumb_image_url VARCHAR(255) NOT NULL DEFAULT '',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  overlord_name VARCHAR(100) NOT NULL DEFAULT '',
  overlord_title VARCHAR(100) NOT NULL DEFAULT '',
  overlord_page_slug VARCHAR(100) NOT NULL DEFAULT '',
  overlord_id INT UNSIGNED NULL,
  status ENUM('available','locked') NOT NULL DEFAULT 'locked',
  lore_status_label VARCHAR(100) NOT NULL DEFAULT 'Lore Coming Soon',
  intro_paragraph_1 TEXT NULL,
  intro_paragraph_2 TEXT NULL,
  layout_orientation ENUM('vertical','horizontal') NOT NULL DEFAULT 'horizontal',
  altitude_top_label VARCHAR(100) NOT NULL DEFAULT '',
  altitude_bottom_label VARCHAR(100) NOT NULL DEFAULT '',
  map_thumb_image_url VARCHAR(255) NOT NULL DEFAULT '',
  map_full_image_url VARCHAR(255) NOT NULL DEFAULT '',
  map_caption VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_layers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  name VARCHAR(100) NOT NULL,
  theme_tags VARCHAR(200) NOT NULL DEFAULT '',
  tagline VARCHAR(150) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  tint_key VARCHAR(20) NOT NULL DEFAULT 'gold',
  CONSTRAINT fk_world_layers_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_layer_sublocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  layer_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  label VARCHAR(100) NOT NULL,
  CONSTRAINT fk_world_layer_sublocations_layer FOREIGN KEY (layer_id) REFERENCES world_layers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Covers both kinds of landmark callout: "restricted" (nested inside a
-- specific layer, e.g. Vault 17 inside Neoh's Spires -- layer_id set) and
-- "distant" (attached to the world itself, outside any layer, e.g. Lios --
-- layer_id NULL).
CREATE TABLE IF NOT EXISTS world_landmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  layer_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  kind ENUM('restricted','distant') NOT NULL DEFAULT 'restricted',
  name VARCHAR(100) NOT NULL,
  tag_label VARCHAR(150) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  CONSTRAINT fk_world_landmarks_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_world_landmarks_layer FOREIGN KEY (layer_id) REFERENCES world_layers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Overlord Control. worlds.overlord_id (above) is a real FK to this table,
-- replacing the old free-text overlord_name/overlord_title/overlord_page_slug
-- columns on worlds -- those three are kept for now (dropped in a short
-- follow-up migration once the cutover is verified live) but are no longer
-- written to by the admin UI.
CREATE TABLE IF NOT EXISTS overlords (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  epithet VARCHAR(100) NOT NULL DEFAULT '',
  world_id INT UNSIGNED NULL,
  pronoun_possessive VARCHAR(10) NOT NULL DEFAULT 'their',
  status ENUM('available','locked') NOT NULL DEFAULT 'locked',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  card_teaser VARCHAR(300) NOT NULL DEFAULT '',
  bio_paragraph_1 TEXT NULL,
  bio_paragraph_2 TEXT NULL,
  bio_paragraph_3 TEXT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT '',
  accent_glow VARCHAR(20) NOT NULL DEFAULT '',
  meta_title VARCHAR(150) NOT NULL DEFAULT '',
  meta_description VARCHAR(300) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_overlords_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE worlds
  ADD CONSTRAINT fk_worlds_overlord FOREIGN KEY (overlord_id) REFERENCES overlords(id) ON DELETE SET NULL;
