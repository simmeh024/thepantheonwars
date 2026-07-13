-- Shared-hosting SQL diagnostics. The application only writes normalized
-- statement fingerprints after the 100ms threshold; parameter values are
-- never persisted.
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
