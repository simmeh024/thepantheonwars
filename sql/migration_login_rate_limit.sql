-- Adds a dedicated table for the login endpoint's own rate limit, separate
-- from the existing login_attempts-backed IP/account throttles. Every POST
-- that reaches api/login.php inserts one row here (valid credentials or
-- not), and the endpoint rejects with 429 once an IP exceeds a short rolling
-- window of requests -- this catches raw automation volume against the
-- endpoint itself, which the credential-attempt-based checks never see
-- because they only log a row once identifier/password have already passed
-- basic validation.
CREATE TABLE IF NOT EXISTS login_rate_limit_hits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
