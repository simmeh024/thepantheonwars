-- Google OAuth identity support.
-- Run manually in phpMyAdmin after deploying the matching application code.
-- The nullable password_hash lets a Google-only account add a password later
-- from Profile Settings without ever storing a placeholder credential.

ALTER TABLE users
  MODIFY password_hash VARCHAR(255) NULL;

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
