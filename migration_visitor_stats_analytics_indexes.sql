-- Visitor Statistics composite indexes.
--
-- Run once in phpMyAdmin against the `pantheonwars` database after deploying
-- the matching heatmap query. Each index starts with created_at because every
-- raw-data analytics card first limits a UTC time window. The trailing
-- columns let MariaDB satisfy the corresponding aggregation from the index
-- rather than reading the full page_views rows.
--
-- Keep idx_created_at: it remains the smallest index for the raw-retention
-- delete and the Recent Visits feed's reverse chronological ordering.

ALTER TABLE page_views
  ADD INDEX idx_created_visitor_user (created_at, visitor_id, user_id),
  ADD INDEX idx_created_path (created_at, path),
  ADD INDEX idx_created_referrer (created_at, referrer_host),
  ADD INDEX idx_created_country (created_at, country_code, country_name);
