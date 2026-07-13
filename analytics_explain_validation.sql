-- Read-only post-migration validation for MariaDB 10.11.
--
-- MariaDB's EXPLAIN ANALYZE equivalent is ANALYZE FORMAT=JSON. Run these in
-- phpMyAdmin after migration_visitor_stats_analytics_indexes.sql. Confirm the
-- "key"/"key_name" field uses the named created_at-leading index, then record
-- the reported r_rows and execution time. On the current small data set the
-- optimiser may still choose a table scan; repeat once page_views is larger.

ANALYZE FORMAT=JSON
SELECT COUNT(*) AS total,
       COUNT(DISTINCT visitor_id) AS unique_visitors,
       SUM(user_id IS NOT NULL) AS member_views
FROM page_views
WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY);

ANALYZE FORMAT=JSON
SELECT path, COUNT(*) AS views
FROM page_views
WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
GROUP BY path
ORDER BY views DESC
LIMIT 10;

ANALYZE FORMAT=JSON
SELECT referrer_host, COUNT(*) AS views
FROM page_views
WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
GROUP BY referrer_host
ORDER BY views DESC
LIMIT 10;

ANALYZE FORMAT=JSON
SELECT country_code, country_name, COUNT(*) AS views
FROM page_views
WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
GROUP BY country_code, country_name
ORDER BY views DESC
LIMIT 10;

ANALYZE FORMAT=JSON
SELECT (DAYOFWEEK(created_at) - 1) AS weekday,
       HOUR(created_at) AS hour,
       COUNT(DISTINCT visitor_id) AS unique_visitors
FROM page_views
WHERE created_at >= DATE_FORMAT(UTC_DATE(), '%Y-01-01')
  AND created_at < (UTC_DATE() + INTERVAL 1 DAY)
GROUP BY weekday, hour;

ANALYZE FORMAT=JSON
SELECT path, referrer_host, created_at
FROM page_views
ORDER BY created_at DESC, id DESC
LIMIT 25;

ANALYZE FORMAT=JSON
SELECT from_path, to_path, SUM(transition_count) AS transitions
FROM page_view_daily_transitions
WHERE include_admin = 0
  AND stat_date >= (UTC_DATE() - INTERVAL 30 DAY)
  AND stat_date < UTC_DATE()
GROUP BY from_path, to_path;
