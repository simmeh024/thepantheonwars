-- Secure, self-service password reset
-- Run once in phpMyAdmin after sql/migration_mail_system.sql has been applied.
-- Raw reset links are never stored: token_hash contains SHA-256(token) only.

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

-- Preserve any custom subject/body copy. This only updates the admin-facing
-- description now that the secure flow is active.
UPDATE mail_templates
SET description = 'Sent after a self-service request. The link is single-use, expires after 30 minutes, and never contains a password.'
WHERE template_key = 'password_reset';
