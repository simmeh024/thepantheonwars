-- Shared, short-lived payloads for expensive Admin Console diagnostics.
-- Safe to run repeatedly in phpMyAdmin before or after code deployment.
CREATE TABLE IF NOT EXISTS admin_runtime_cache (
  cache_key VARCHAR(100) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
