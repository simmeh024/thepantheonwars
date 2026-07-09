-- Fix the books table's stray latin1_swedish_ci collation (every other
-- table in this schema is utf8mb4_unicode_ci -- found via System Status
-- research, confirmed via information_schema.TABLES).
ALTER TABLE books CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- New table backing the System Status "CPU (Shared)" 24h line chart.
-- Populated once a minute by api/cron/sample-load.php via a cPanel Cron Job;
-- rows older than ~25h are pruned on every insert.
CREATE TABLE IF NOT EXISTS cpu_load_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  load1 DECIMAL(6,2) NOT NULL,
  load5 DECIMAL(6,2) NOT NULL,
  load15 DECIMAL(6,2) NOT NULL,
  recorded_at DATETIME NOT NULL,
  KEY idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
