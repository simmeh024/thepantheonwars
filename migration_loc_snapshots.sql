-- migration_loc_snapshots.sql
-- Daily snapshots of total lines of code, backing the admin Home Development
-- Snapshot card's "Total Lines of Code" tile + its "+N today" delta.
-- Run by hand via phpMyAdmin's SQL tab, then fold into sql/schema.sql per project convention.

CREATE TABLE loc_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  captured_at DATE NOT NULL UNIQUE,
  total_lines INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
