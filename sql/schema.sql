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
  -- NULL is intentional for OAuth-only accounts. A member can add a local
  -- password later from Profile Settings.
  password_hash VARCHAR(255) NULL,
  display_name VARCHAR(50) NOT NULL,
  overlord_affinity VARCHAR(50) DEFAULT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'member',
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME DEFAULT NULL,
  last_login_ip VARCHAR(64) DEFAULT NULL,
  last_active_at DATETIME DEFAULT NULL,
  -- Offline is derived from inactive/revoked sessions. Signed-in members can
  -- choose one of the three visible states below.
  presence_status ENUM('online','away','inactive') NOT NULL DEFAULT 'online',
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

-- Public news updates, managed through Admin Console > Content > News
-- Management. Posts can be relayed by BH-4 or attributed to the admin who
-- published them. The body is deliberately plain text; news.js safely turns
-- blank-line-separated text into public paragraphs.
CREATE TABLE IF NOT EXISTS news_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  author_type ENUM('bh4','member') NOT NULL DEFAULT 'bh4',
  author_user_id INT UNSIGNED NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_news_slug (slug),
  KEY idx_news_published (published_at, id),
  CONSTRAINT fk_news_posts_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable tag catalogue and the many-to-many relation for public news.
-- Tag rows intentionally remain after they have no posts, preserving useful
-- autocomplete suggestions for editors creating the next update.
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
  KEY idx_created_at_id (created_at, id),
  -- Cover the time-windowed aggregate cards without reading full rows.
  KEY idx_created_visitor_user (created_at, visitor_id, user_id),
  KEY idx_created_path (created_at, path),
  KEY idx_created_referrer (created_at, referrer_host),
  KEY idx_created_country (created_at, country_code, country_name),
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

-- One external identity per provider per member. No OAuth access or refresh
-- tokens are stored: this table keeps only the provider's stable subject and
-- the email that was verified during linking.
CREATE TABLE IF NOT EXISTS oauth_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  provider_subject VARCHAR(255) NOT NULL,
  provider_email VARCHAR(255) NOT NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  UNIQUE KEY uniq_oauth_provider_subject (provider, provider_subject),
  UNIQUE KEY uniq_oauth_user_provider (user_id, provider),
  KEY idx_oauth_user (user_id),
  CONSTRAINT fk_oauth_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe aggregate metadata derived from GitHub commit diffs. It deliberately
-- excludes source code and file paths; the Dispatch Draft Translator uses it
-- only for reader-facing context such as affected product areas and file types.
CREATE TABLE IF NOT EXISTS dispatch_diff_context (
  dispatch_id INT UNSIGNED NOT NULL PRIMARY KEY,
  files_changed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  extensions_json VARCHAR(255) NOT NULL DEFAULT '[]',
  areas_json VARCHAR(255) NOT NULL DEFAULT '[]',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispatch_diff_context_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rule-based drafts are deliberately isolated from approved translations.
-- A draft is never exposed through the public dispatch APIs; an admin must
-- approve or edit it first, which moves its text into dispatch_translations.
CREATE TABLE IF NOT EXISTS dispatch_translation_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  sha VARCHAR(40) NOT NULL,
  draft TEXT NOT NULL,
  source ENUM('rule_based', 'rule_based_spacy') NOT NULL DEFAULT 'rule_based',
  draft_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_translation_draft (dispatch_id),
  KEY idx_draft_sha (sha),
  CONSTRAINT fk_dispatch_translation_drafts_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data-subject requests submitted through privacy-request.html. Fulfilment is
-- deliberately manual: it creates a permissioned admin work item rather than
-- automatically deleting or exporting any account data.
CREATE TABLE IF NOT EXISTS privacy_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_user_id INT UNSIGNED DEFAULT NULL,
  requester_email VARCHAR(255) NOT NULL,
  request_type ENUM('access','rectification','erasure','portability','restriction','objection','other') NOT NULL,
  message TEXT DEFAULT NULL,
  status ENUM('submitted','identity_check','in_progress','fulfilled','partially_fulfilled','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  staff_resolution TEXT DEFAULT NULL,
  handled_by INT UNSIGNED DEFAULT NULL,
  handled_at DATETIME DEFAULT NULL,
  due_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status_due_created (status, due_at, created_at),
  KEY idx_requester_created (requester_user_id, created_at),
  CONSTRAINT fk_privacy_requests_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_privacy_requests_handler FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application-level slow-query diagnostics for shared hosting, where the
-- database slow-query log and Performance Schema are not accessible.
CREATE TABLE IF NOT EXISTS sql_performance_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  query_hash CHAR(64) NOT NULL,
  query_fingerprint TEXT NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  request_method VARCHAR(10) NOT NULL,
  category VARCHAR(64) NOT NULL,
  execution_ms DECIMAL(10,3) NOT NULL,
  rows_affected INT UNSIGNED NOT NULL DEFAULT 0,
  severity ENUM('info','warning','slow','critical') NOT NULL,
  user_id INT UNSIGNED NULL,
  request_id CHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_severity (created_at, severity),
  KEY idx_hash_created (query_hash, created_at),
  KEY idx_endpoint_created (endpoint, created_at),
  CONSTRAINT fk_sql_performance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily, pre-aggregated page-to-page transitions used by the Visitor
-- Statistics Sankey chart. Two rows are stored per path pair: all traffic
-- and the default admin-excluded view. See api/cron/rollup-page-view-journeys.php.
CREATE TABLE IF NOT EXISTS page_view_daily_transitions (
  stat_date DATE NOT NULL,
  include_admin TINYINT(1) NOT NULL,
  from_path VARCHAR(255) NOT NULL,
  to_path VARCHAR(255) NOT NULL,
  transition_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (stat_date, include_admin, from_path, to_path),
  KEY idx_include_date (include_admin, stat_date)
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

-- Revocable account-session registry. Hashes only: never persist raw PHP
-- session IDs or opaque browser session tokens.
CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  php_session_id_hash CHAR(64) NOT NULL,
  device_label VARCHAR(120) NOT NULL,
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  browser_name VARCHAR(80) NOT NULL DEFAULT 'Unknown browser',
  operating_system VARCHAR(80) NOT NULL DEFAULT 'Unknown operating system',
  ip_address VARCHAR(64) NOT NULL,
  country_code CHAR(2) NULL,
  country_name VARCHAR(100) NULL,
  auth_provider VARCHAR(30) NOT NULL DEFAULT 'password',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(64) NULL,
  is_persistent TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_session_token_hash (session_token_hash),
  KEY idx_user_active (user_id, revoked_at, expires_at),
  KEY idx_user_last_active (user_id, last_active_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived, shared values for expensive Admin Console probes. These are
-- non-user-specific status/summary payloads only; permission checks still run
-- before the cached values are returned by their endpoints.
CREATE TABLE IF NOT EXISTS admin_runtime_cache (
  cache_key VARCHAR(100) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
