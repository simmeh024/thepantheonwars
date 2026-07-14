-- Run once in phpMyAdmin after deploying the User Sessions feature.
-- Only SHA-256 hashes of opaque identifiers are stored; raw cookies and
-- session tokens never enter this table.
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
