-- Two-factor authentication for password sign-ins.
-- Run once in phpMyAdmin against rdy3i6my40b0_pantheonwars AFTER deployment.
-- The secret is AES-256-GCM encrypted by api/two-factor-helpers.php before it
-- reaches this table; never insert a plaintext authenticator secret manually.

CREATE TABLE IF NOT EXISTS user_two_factor (
  user_id INT UNSIGNED PRIMARY KEY,
  secret_ciphertext VARCHAR(255) NOT NULL,
  enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_counter BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissioned staff recovery action; admins remain superusers, while custom
-- roles can be explicitly granted this narrow recovery permission.
INSERT INTO permissions (`key`, label, category)
VALUES ('members.reset_two_factor', 'Reset Two-Factor Authentication', 'Community')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
