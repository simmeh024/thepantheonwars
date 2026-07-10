-- Adds visitor/page-view tracking backing the new "Visitor Statistics"
-- admin page (under the Home nav category). Run by hand via phpMyAdmin's
-- SQL tab against the `pantheonwars` database.
--
-- page_views is the raw per-hit log, pruned to a 90-day rolling window by
-- api/cron/rollup-page-views.php (same "raw + prune" shape as
-- cpu_load_history, just a longer window since site traffic is far lower
-- volume than a per-minute CPU sample). page_view_daily_stats is a
-- permanent one-row-per-day rollup so the "visits over time" chart can
-- show longer trends without the raw table growing unbounded.

CREATE TABLE IF NOT EXISTS page_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  referrer_host VARCHAR(255) NULL,
  visitor_id CHAR(36) NOT NULL,
  user_id INT UNSIGNED NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_at (created_at),
  KEY idx_visitor_id (visitor_id),
  CONSTRAINT fk_page_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_view_daily_stats (
  stat_date DATE PRIMARY KEY,
  total_views INT UNSIGNED NOT NULL DEFAULT 0,
  unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
  member_views INT UNSIGNED NOT NULL DEFAULT 0,
  guest_views INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New permission gating the admin nav link + all visitor-stats endpoints.
-- No role_permissions seed row on purpose (same reasoning as
-- migration_ip_permission.sql): 'admin' already sees everything via
-- is_superuser, and this is genuinely new, opt-in data -- grant it to a
-- role explicitly via the Roles & Permissions admin UI if desired.
INSERT INTO permissions (`key`, label, category) VALUES
  ('analytics.view', 'View visitor statistics', 'Analytics')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
