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
  -- NULL until the address has been confirmed through a trusted provider or
  -- a future first-party verification link. The timestamp doubles as a
  -- compact audit reference without exposing it on public member pages.
  email_verified_at DATETIME DEFAULT NULL,
  -- Every registration path (password, Google, admin-created) omits this
  -- column from its INSERT, so new accounts always land on this default.
  -- Toggle lives in Profile Settings > Change Password section.
  newsletter_subscribed TINYINT(1) NOT NULL DEFAULT 1,
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
  -- Reputation points: +1 per topic/comment authored, +2 per like received
  -- (reversed on unlike). Drives the reputation bar against reputation_levels.
  reputation INT UNSIGNED NOT NULL DEFAULT 0,
  -- Daily-return reward is claimed at most once per rolling 24 hours.
  last_reputation_return_at DATETIME NULL DEFAULT NULL,
  -- One of the fixed Overlord resonance icon keys the user has unlocked (see
  -- user_unlocked_icons), or NULL to show no icon next to the reputation bar.
  selected_icon VARCHAR(40) NULL,
  banned_at DATETIME DEFAULT NULL,
  banned_until DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_email (email),
  CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-defined reputation ranks (Reputation Levels). Ordered by threshold
-- ascending; a user's current/next level and progress toward it are computed
-- from users.reputation against this table, never stored redundantly. See
-- migration_reputation.sql for the one-time seed data and permissions.
CREATE TABLE IF NOT EXISTS reputation_levels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  threshold INT UNSIGNED NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#a279ec',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reputation_levels_threshold (threshold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (user, unlocked resonance icon). See migration_reputation_icons.sql
-- for the fixed icon-key catalog and the 100% quiz-result unlock trigger.
CREATE TABLE IF NOT EXISTS user_unlocked_icons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  icon_key VARCHAR(40) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_unlocked_icons (user_id, icon_key),
  CONSTRAINT fk_user_unlocked_icons_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Board categories (Forum Control's "Board Categories" list). A pure display
-- grouping for the public forum index -- pw_can_see_board() in
-- api/helpers.php still gates per-board visibility independently of category.
CREATE TABLE IF NOT EXISTS forum_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  category_id INT UNSIGNED NOT NULL,
  accent_color VARCHAR(20) NOT NULL DEFAULT '#a279ec',
  is_protected TINYINT(1) NOT NULL DEFAULT 0,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_forum_boards_category FOREIGN KEY (category_id) REFERENCES forum_categories(id)
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
  edited_by INT UNSIGNED NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_board (board),
  KEY idx_board_active_created (board, is_deleted, created_at),
  KEY idx_user_active_created (user_id, is_deleted, created_at),
  CONSTRAINT fk_topics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_topics_edited_by FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
  FULLTEXT INDEX ft_topics_title_body (title, body)
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
  edited_by INT UNSIGNED NULL,
  KEY idx_topic_id (topic_id),
  KEY idx_topic_active_created (topic_id, is_deleted, created_at),
  KEY idx_user_active_created (user_id, is_deleted, created_at),
  KEY idx_parent_id (parent_id),
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_quoted FOREIGN KEY (quoted_comment_id) REFERENCES comments(id) ON DELETE SET NULL,
  CONSTRAINT fk_comments_edited_by FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
  FULLTEXT INDEX ft_comments_body (body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shard/Ward/Ember reactions on a reply (see REACTIONS in community.html).
-- One reaction per member per comment -- changing your reaction type
-- updates this same row rather than adding a second one (see
-- api/comments/react.php).
CREATE TABLE IF NOT EXISTS comment_reactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  reaction_type VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_comment (comment_id, user_id),
  KEY idx_comment (comment_id),
  CONSTRAINT fk_reaction_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-synced unread tracking, mirroring the client's localStorage "last
-- seen" shape 1:1 so logged-in members get cross-device read state while
-- guests keep the existing localStorage-only fallback (see community.html).
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

-- One optional poll per topic, created only at topic-creation time (not
-- editable afterward -- options shouldn't change once anyone may have
-- voted). Single-choice, one vote per member.
CREATE TABLE IF NOT EXISTS topic_polls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id INT UNSIGNED NOT NULL,
  question VARCHAR(300) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_topic_poll (topic_id),
  CONSTRAINT fk_topic_polls_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topic_poll_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_poll_options_poll (poll_id, sort_order),
  CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES topic_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One vote per (poll, user); re-voting replaces the previous choice rather
-- than adding a second row.
CREATE TABLE IF NOT EXISTS topic_poll_votes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_poll_user (poll_id, user_id),
  KEY idx_poll_votes_option (option_id),
  CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES topic_polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_option FOREIGN KEY (option_id) REFERENCES topic_poll_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Watch this thread" -- distinct from topic_bookmarks (a manual save with
-- no notification behaviour). A subscription actually notifies on every new
-- reply. A topic's own creator and anyone who replies to it are
-- auto-subscribed (see api/topics/create.php / api/comments/post.php);
-- members can unsubscribe from the kebab menu at any time.
CREATE TABLE IF NOT EXISTS topic_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subscription (user_id, topic_id),
  KEY idx_subscriptions_topic (topic_id),
  CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscriptions_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tag is auto-assigned by pw_dispatch_categorize() (api/dispatch-helpers.php)
-- on every webhook push / manual re-sync, then may be corrected by an admin
-- in Dispatch Control (api/admin/dispatches/update.php, audited as
-- "category_edited" and recorded in dispatch_category_overrides).
-- category_confidence is a 0-100 explainable score (conventional-commit
-- prefix + word-boundary subject/body keyword hits + diff-context file-scope
-- signal, see pw_dispatch_categorize()); category_source flips to 'manual'
-- (confidence reset to 100) the moment a human explicitly saves a category,
-- which is what lets the Home "needs review" queue and Dispatch Control's
-- low-confidence badge both clear once someone has actually looked.
CREATE TABLE IF NOT EXISTS dispatch_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sha VARCHAR(40) NOT NULL,
  subject VARCHAR(500) NOT NULL,
  body TEXT DEFAULT NULL,
  tag ENUM('feature','improvement','fix','performance','ui_ux','lore','infrastructure','refactor','experimental') NOT NULL DEFAULT 'feature',
  category_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  category_source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  author VARCHAR(100) NOT NULL,
  committed_at DATETIME NOT NULL,
  url VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sha (sha),
  KEY idx_committed_at (committed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per admin category correction -- an evidence trail for future
-- keyword/weight tuning ("what does the heuristic keep getting wrong, and
-- to what"), never mutated after insert.
CREATE TABLE IF NOT EXISTS dispatch_category_overrides (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  previous_tag VARCHAR(20) NOT NULL,
  previous_confidence TINYINT UNSIGNED NOT NULL,
  previous_source ENUM('auto','manual') NOT NULL,
  new_tag VARCHAR(20) NOT NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_category_overrides_dispatch (dispatch_id),
  CONSTRAINT fk_category_overrides_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_category_overrides_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public news updates, managed through Admin Console > Content > News
-- Management. Posts can be relayed by BH-4 or attributed to the admin who
-- published them. The body supports a small server-sanitised editorial HTML
-- subset (including images from uploads/news-images); legacy plain-text bodies
-- remain readable as blank-line-separated public paragraphs.
CREATE TABLE IF NOT EXISTS news_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  header_image_url VARCHAR(255) NULL,
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

-- Flat, public replies attached to a News transmission. These are deliberately
-- separate from forum comments: a news post is editorial content, not a forum
-- topic, but replies retain the same account, role, and moderation safeguards.
CREATE TABLE IF NOT EXISTS news_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  news_post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_news_comments_post_created (news_post_id, created_at),
  KEY idx_news_comments_user_created (user_id, created_at),
  CONSTRAINT fk_news_comments_post FOREIGN KEY (news_post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Reports raised by members against forum content, a News reply, or a specific
-- private message, reviewed by moderators/admins on the admin console's Topic
-- Reports page. Staff can see private-message content only through this report
-- path; it is never exposed as a browseable moderation inbox. resolution
-- is filled in when a mod closes the report; resolved_by/resolved_at record
-- who closed it and when. Quick actions taken from that page (lock/move the
-- topic, delete the topic or comment) are separate operations logged to
-- admin_activity_log -- they don't automatically close the report.
CREATE TABLE IF NOT EXISTS content_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('topic','comment','news_comment','direct_message') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  reporter_user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  category ENUM('spam','harassment','off_topic','spoiler_untagged','other') NOT NULL DEFAULT 'other',
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

-- See migration_reputation_expansion.sql. These tables retain the complete
-- reputation audit trail, editable reward rules, timed targeted events, and
-- permanent member achievements.
CREATE TABLE IF NOT EXISTS reputation_reward_rules (
  `key` VARCHAR(40) NOT NULL PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  base_points SMALLINT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reputation_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  multiplier TINYINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reward_keys_json TEXT NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reputation_events_window (is_enabled, starts_at, ends_at),
  CONSTRAINT fk_reputation_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reputation_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  reward_key VARCHAR(50) NOT NULL,
  label VARCHAR(140) NOT NULL,
  base_points SMALLINT NOT NULL,
  multiplier TINYINT UNSIGNED NOT NULL DEFAULT 1,
  points SMALLINT NOT NULL,
  source_type VARCHAR(40) NULL,
  source_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reputation_ledger_member (user_id, created_at),
  INDEX idx_reputation_ledger_created (created_at),
  CONSTRAINT fk_reputation_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reputation_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_reputation_achievements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  achievement_key VARCHAR(40) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_reputation_achievement (user_id, achievement_key),
  CONSTRAINT fk_user_reputation_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member-selected subset of unlocked achievements shown on their public profile.
-- See migration_reputation_showcase.sql; positions are one through ten.
CREATE TABLE IF NOT EXISTS user_reputation_achievement_showcase (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  achievement_key VARCHAR(40) NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reputation_showcase_member_achievement (user_id, achievement_key),
  UNIQUE KEY uq_reputation_showcase_member_position (user_id, position),
  CONSTRAINT fk_reputation_showcase_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First-visit lore discovery records. Every member can earn the discovery
-- award once per available World and once per available Overlord.
CREATE TABLE IF NOT EXISTS user_lore_discoveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  entity_type ENUM('world','overlord') NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lore_discovery_member_record (user_id, entity_type, entity_id),
  KEY idx_lore_discovery_member (user_id, discovered_at),
  CONSTRAINT fk_lore_discovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-managed personality quiz content. Each question has exactly six
-- ordered options: the order maps to the fixed six-Overlord score catalog.
CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_text VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_quiz_questions_active_order (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  score_index TINYINT UNSIGNED NOT NULL,
  option_text VARCHAR(1000) NOT NULL,
  UNIQUE KEY uq_quiz_option_score (question_id, score_index),
  CONSTRAINT fk_quiz_options_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private one-to-one member conversations. The participant ids are always
-- stored in ascending order, which makes the unique key the authoritative
-- guarantee that a pair of members has only one conversation.
CREATE TABLE IF NOT EXISTS direct_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_low_id INT UNSIGNED NOT NULL,
  user_high_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  last_message_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  user_low_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_high_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_direct_conversation_pair (user_low_id, user_high_id),
  KEY idx_direct_conversations_recent (last_message_at, id),
  CONSTRAINT fk_direct_conversations_low FOREIGN KEY (user_low_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversations_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_direct_messages_conversation (conversation_id, id),
  KEY idx_direct_messages_sender_created (sender_user_id, created_at),
  CONSTRAINT fk_direct_messages_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A block is directional. The sender-side staff override is enforced in PHP:
-- moderators and admins can still deliver an essential staff message, while
-- ordinary member-to-member messaging respects a block in either direction.
CREATE TABLE IF NOT EXISTS user_blocks (
  blocker_user_id INT UNSIGNED NOT NULL,
  blocked_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_user_id, blocked_user_id),
  CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member presentation state for private conversations. Pins are personal:
-- pinning a conversation never changes its position for the other participant.
CREATE TABLE IF NOT EXISTS direct_conversation_preferences (
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, conversation_id),
  KEY idx_direct_conversation_preferences_pinned (user_id, is_pinned, updated_at),
  CONSTRAINT fk_direct_conversation_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_conversation_preferences_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ephemeral typing signals. The application only treats rows touched in the
-- last 12 seconds as active and removes them when composing stops.
CREATE TABLE IF NOT EXISTS direct_message_typing (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_direct_message_typing_active (conversation_id, updated_at),
  CONSTRAINT fk_direct_message_typing_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_direct_message_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_likes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('topic','comment') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  -- Exact amount granted to the content author, retained so an unlike can
  -- reverse a boosted event award even after that event has expired.
  reputation_awarded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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

-- Dispatch Composer: manually-written blog posts that use approved dispatches
-- as reference/traceability material rather than injecting their text
-- directly. slug is only a working preview value scoped to this table; the
-- real published slug is resolved against news_posts separately at publish
-- time (see api/admin/dispatch-composer/composer-helpers.php).
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
-- draft. admin_note is a private writing aid, never published.
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

-- Editorial content for transactional mail. Transport credentials are never
-- stored here: shared hosting uses PHP mail(), while sender identity and the
-- explicit enabled switch live in app_settings. The four seeded keys are a
-- closed allowlist in api/mail.php, so templates cannot become arbitrary mail
-- relays through a crafted request.
CREATE TABLE IF NOT EXISTS mail_templates (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL,
  subject VARCHAR(180) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mail_template_key (template_key),
  CONSTRAINT fk_mail_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Troubleshooting metadata for transactional delivery and authenticated
-- inbound-mail webhooks. Message bodies are deliberately never stored.
CREATE TABLE IF NOT EXISTS mail_delivery_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('inbound', 'outbound') NOT NULL,
  status VARCHAR(32) NOT NULL,
  template_key VARCHAR(40) NULL,
  sender_email VARCHAR(255) NULL,
  recipient_email VARCHAR(255) NULL,
  subject VARCHAR(255) NULL,
  provider_message_id VARCHAR(255) NULL,
  detail VARCHAR(255) NULL,
  body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mail_log_direction_created (direction, created_at),
  KEY idx_mail_log_status_created (status, created_at),
  KEY idx_mail_log_recipient_created (recipient_email, created_at),
  KEY idx_mail_log_provider_message (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived, single-use password-reset credentials. The raw 256-bit token
-- is emailed in a URL fragment and is never stored here; token_hash is a
-- SHA-256 hash used for lookup. Request rate-limit queries use the two
-- composite indexes below, while one active token per user is enforced in
-- application logic by marking the previous record used.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  requested_ip VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_user_active (user_id, used_at, expires_at),
  KEY idx_password_reset_ip_created (requested_ip, created_at),
  CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- A dedicated, tighter rate limit for the login endpoint itself, separate
-- from the login_attempts-backed IP/account throttles above. One row per
-- POST reaching api/login.php, success or failure or even malformed --
-- see pw_login_endpoint_rate_limited() in api/helpers.php.
CREATE TABLE IF NOT EXISTS login_rate_limit_hits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw per-page-view log backing the "Visitor Statistics" admin page.
-- Pruned to a 90-day rolling window by api/cron/rollup-page-views.php,
-- which also rolls each finished day up into page_view_daily_stats below.
CREATE TABLE IF NOT EXISTS page_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  -- Which specific World/Book/Overlord a visit to a shared template page
  -- (world.html, overlord.html, chapter-one.html) was actually about.
  -- Deliberately separate from `path`, which stays pathname-only so
  -- existing path-grouped aggregates (Top Pages, journeys, heatmap) never
  -- fragment. Only populated from the day this column shipped onward.
  query_string VARCHAR(255) NULL,
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

-- Authenticator-app TOTP for local password sign-ins. The secret is encrypted
-- before storage using TWO_FACTOR_ENCRYPTION_KEY from outside-webroot config.
-- Google OAuth uses the provider's own account protection and does not use it.
CREATE TABLE IF NOT EXISTS user_two_factor (
  user_id INT UNSIGNED PRIMARY KEY,
  secret_ciphertext VARCHAR(255) NOT NULL,
  enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_counter BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Sentence-transformer embedding cache for approved translations, one row per
-- published dispatch_translations row -- see migration_dispatch_translation_embeddings.sql
-- and docs/dispatch-embeddings.md. Lets a new draft's semantic-similarity
-- lookup compare against the whole approved corpus in plain PHP (cosine
-- similarity) without re-encoding anything except the one incoming commit.
CREATE TABLE IF NOT EXISTS dispatch_translation_embeddings (
  dispatch_id INT UNSIGNED NOT NULL PRIMARY KEY,
  model VARCHAR(64) NOT NULL,
  translation_hash CHAR(64) NOT NULL,
  embedding_json TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispatch_translation_embeddings_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit Good/Bad quality rating an admin can leave on any published
-- translation, one row per (dispatch, rater) so a rater can change their
-- mind and different raters' independent votes are all preserved.
CREATE TABLE IF NOT EXISTS dispatch_translation_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  rating ENUM('good', 'bad') NOT NULL,
  rated_by_user_id INT UNSIGNED NOT NULL,
  rated_by_username VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dispatch_rater (dispatch_id, rated_by_user_id),
  KEY idx_dispatch_id (dispatch_id),
  CONSTRAINT fk_dispatch_translation_feedback_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dispatch_translation_feedback_user
    FOREIGN KEY (rated_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automatic log, one row per publish/edit event. similarity_pct is computed
-- with PHP's built-in similar_text() against whatever text existed
-- immediately before this event (the engine's own rule-based draft, the
-- previously published translation, or NULL when there was nothing to
-- compare against). Purely observational -- never read by the translator
-- itself.
CREATE TABLE IF NOT EXISTS dispatch_translation_edit_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispatch_id INT UNSIGNED NOT NULL,
  event ENUM('auto_published', 'manual_save') NOT NULL,
  similarity_pct DECIMAL(5,2) DEFAULT NULL,
  previous_length INT UNSIGNED NOT NULL DEFAULT 0,
  new_length INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dispatch_id (dispatch_id),
  CONSTRAINT fk_dispatch_translation_edit_events_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly self-tuning maintenance pass: a human-readable summary of the past
-- week's Good/Bad feedback and edit-distance data (overall stats, per-tag
-- breakdown, confidence-evidence breakdown, weak-translation clusters).
-- Advisory only -- nothing here is ever auto-applied to the translator.
CREATE TABLE IF NOT EXISTS dispatch_quality_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  window_start DATE NOT NULL,
  window_end DATE NOT NULL,
  summary_json TEXT NOT NULL,
  status ENUM('unread', 'reviewed') NOT NULL DEFAULT 'unread',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  reviewed_by_username VARCHAR(80) DEFAULT NULL,
  KEY idx_generated_at (generated_at)
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

-- Public-site notification system: one row per notification, including likes,
-- forum activity, announcements, and collapsed per-conversation direct-message
-- alerts. See
-- api/messages/like.php, api/topics/create.php, api/comments/post.php, and
-- api/admin/topic-reports/resolve.php for the write sites, and
-- api/notifications/*.php for reads.
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message','new_device_login') NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  topic_id INT UNSIGNED NULL,
  comment_id INT UNSIGNED NULL,
  report_id INT UNSIGNED NULL,
  world_id INT UNSIGNED NULL,
  news_slug VARCHAR(120) NULL,
  conversation_id BIGINT UNSIGNED NULL,
  direct_message_id BIGINT UNSIGNED NULL,
  excerpt VARCHAR(200) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_created (user_id, created_at),
  KEY idx_user_unread (user_id, is_read),
  KEY idx_notification_conversation (conversation_id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_notifications_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_report FOREIGN KEY (report_id) REFERENCES content_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_conversation FOREIGN KEY (conversation_id) REFERENCES direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_direct_message FOREIGN KEY (direct_message_id) REFERENCES direct_messages(id) ON DELETE CASCADE
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
  notif_news_published TINYINT(1) NOT NULL DEFAULT 1,
  notif_topic_reply TINYINT(1) NOT NULL DEFAULT 1,
  notif_icon_unlocked TINYINT(1) NOT NULL DEFAULT 1,
  notif_new_device_login TINYINT(1) NOT NULL DEFAULT 1,
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

-- Book Control: powers books.html (replaces the original hand-authored
-- markup) plus the admin CRUD. saga_phase/writing_stage are small closed
-- enums stored as plain integers (matched to a label lookup in PHP/JS, not
-- a SQL ENUM) predating this table's later columns' UNSIGNED convention --
-- kept signed here to match the live column types exactly rather than
-- silently changing them during a documentation-only fix. preview_* fields
-- back the optional "Chapter One" teaser card; buy_*_url are the retailer
-- links shown on each book's card.
CREATE TABLE IF NOT EXISTS books (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_number INT NOT NULL UNIQUE,
  saga_phase TINYINT NOT NULL,
  writing_stage TINYINT NOT NULL DEFAULT 1,
  title VARCHAR(255) NOT NULL,
  status_label VARCHAR(100) NOT NULL,
  meta_text VARCHAR(500) DEFAULT NULL,
  description MEDIUMTEXT DEFAULT NULL,
  cover_image_url VARCHAR(500) DEFAULT NULL,
  character_image_url VARCHAR(500) DEFAULT NULL,
  character_alt VARCHAR(255) DEFAULT NULL,
  preview_enabled TINYINT(1) NOT NULL DEFAULT 0,
  preview_eyebrow VARCHAR(255) DEFAULT NULL,
  preview_lede VARCHAR(500) DEFAULT NULL,
  preview_hero_image_url VARCHAR(500) DEFAULT NULL,
  preview_body MEDIUMTEXT DEFAULT NULL,
  preview_quote MEDIUMTEXT DEFAULT NULL,
  preview_quote_cite VARCHAR(255) DEFAULT NULL,
  buy_kobo_url VARCHAR(500) DEFAULT NULL,
  buy_amazon_url VARCHAR(500) DEFAULT NULL,
  buy_apple_url VARCHAR(500) DEFAULT NULL,
  buy_bn_url VARCHAR(500) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One private reading shelf per member. `reading` is the one currently-open
-- title shown on their public profile; the application maintains one or none
-- of those rows for each member.
CREATE TABLE IF NOT EXISTS user_book_progress (
  user_id INT UNSIGNED NOT NULL,
  book_id INT NOT NULL,
  status ENUM('not_started','reading','finished') NOT NULL DEFAULT 'not_started',
  -- Reputation is awarded once per book: +3 at the first Reading state and
  -- +5 at the first Finished state. These timestamps prevent status toggles
  -- from becoming a repeatable reputation source.
  started_at DATETIME NULL DEFAULT NULL,
  finished_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, book_id),
  KEY idx_user_book_progress_current (user_id, status, updated_at),
  CONSTRAINT fk_user_book_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_book_progress_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
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

-- Fictional, admin-controlled atmospheric profiles for the dedicated World
-- Record pages. Forecast values are derived deterministically from this
-- profile, the UTC date, and forecast_revision; no third-party weather API is
-- involved. The first production profile is the Neoh pilot.
CREATE TABLE IF NOT EXISTS world_weather_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  location_label VARCHAR(120) NOT NULL DEFAULT '',
  climate_label VARCHAR(160) NOT NULL DEFAULT '',
  current_condition VARCHAR(80) NOT NULL DEFAULT '',
  current_secondary VARCHAR(120) NOT NULL DEFAULT '',
  current_temp_c SMALLINT NOT NULL DEFAULT 0,
  tomorrow_condition VARCHAR(80) NOT NULL DEFAULT '',
  tomorrow_temp_c SMALLINT NOT NULL DEFAULT 0,
  forecast_min_c SMALLINT NOT NULL DEFAULT -10,
  forecast_max_c SMALLINT NOT NULL DEFAULT 30,
  humidity_min TINYINT UNSIGNED NOT NULL DEFAULT 40,
  humidity_max TINYINT UNSIGNED NOT NULL DEFAULT 90,
  precipitation_min TINYINT UNSIGNED NOT NULL DEFAULT 0,
  precipitation_max TINYINT UNSIGNED NOT NULL DEFAULT 100,
  wind_min_kph SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wind_max_kph SMALLINT UNSIGNED NOT NULL DEFAULT 80,
  condition_pool_json TEXT NOT NULL,
  hazard_note VARCHAR(255) NOT NULL DEFAULT '',
  forecast_revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_world_weather_world (world_id),
  CONSTRAINT fk_world_weather_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_world_weather_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
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
  decrees TEXT NULL,
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

-- Known Figures Control: flat entity powering known-figures.html's cinematic
-- chronicle. `motif` selects one of a small hand-authored animation/veil
-- preset library (js/known-figures.js), `accent_color` drives the
-- eyebrow/glyph/portrait-border color -- see migration_known_figures.sql.
CREATE TABLE IF NOT EXISTS known_figures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  eyebrow VARCHAR(150) NOT NULL DEFAULT '',
  status_line VARCHAR(200) NOT NULL DEFAULT '',
  portrait_image_url VARCHAR(255) NOT NULL DEFAULT '',
  body_paragraph_1 TEXT NULL,
  body_paragraph_2 TEXT NULL,
  quote_text VARCHAR(400) NOT NULL DEFAULT '',
  quote_cite VARCHAR(150) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT '#c7ccd6',
  motif ENUM('pulse','glitch','twirl','glint','none') NOT NULL DEFAULT 'none',
  signature_label VARCHAR(150) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Soundtrack Control: flat entity powering soundtracks.html. spotify_embed_type
-- and spotify_embed_id are parsed once from the admin-pasted spotify_url at
-- save time, so the public/admin embed iframe is built from a fixed template
-- rather than re-parsing the URL -- see migration_soundtracks.sql.
CREATE TABLE IF NOT EXISTS soundtracks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eyebrow VARCHAR(150) NOT NULL DEFAULT '',
  heading VARCHAR(200) NOT NULL DEFAULT '',
  description TEXT NULL,
  spotify_url VARCHAR(500) NOT NULL DEFAULT '',
  spotify_embed_type ENUM('album','playlist','track') NOT NULL DEFAULT 'album',
  spotify_embed_id VARCHAR(64) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
