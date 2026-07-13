-- Pre-aggregated daily transitions for the Visitor Statistics Sankey chart.
-- Run once in phpMyAdmin after deploying the matching code, then schedule:
-- 5 1 * * * curl -s -o /dev/null "https://thepantheonwars.com/api/cron/rollup-page-view-journeys.php?key=<CRON_SAMPLE_KEY>"
-- 01:05 UTC leaves more than the 30-minute session window after midnight.
-- The first cron run backfills the current 90-day raw retention window;
-- subsequent runs rebuild only the most recent two completed days.

CREATE TABLE IF NOT EXISTS page_view_daily_transitions (
  stat_date DATE NOT NULL,
  include_admin TINYINT(1) NOT NULL,
  from_path VARCHAR(255) NOT NULL,
  to_path VARCHAR(255) NOT NULL,
  transition_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (stat_date, include_admin, from_path, to_path),
  KEY idx_include_date (include_admin, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
