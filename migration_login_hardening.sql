-- Login hardening: backs api/login.php's IP-based rate limiting and gives a
-- failed-login audit trail for non-admin accounts (previously only admin
-- logins were logged, via admin_activity_log). No dedicated cron for this
-- table -- pw_log_login_attempt() in api/helpers.php opportunistically
-- prunes rows older than 90 days on ~2% of inserts.
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(64) NOT NULL,
  identifier VARCHAR(255) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_created (ip_address, created_at),
  KEY idx_identifier_created (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
