<?php
/**
 * Cron-only endpoint: rolls every finished day's raw page_views rows up
 * into one page_view_daily_stats row per day, then prunes page_views back
 * to a 90-day rolling window. Invoked once/day by a cPanel Cron Job hitting
 * this URL with ?key=<CRON_SAMPLE_KEY> (the same shared secret that gates
 * api/cron/sample-load.php -- both are cron-only, publicly-reachable
 * endpoints with the same trust boundary, so one secret covers both).
 *
 * Recomputes the rollup for every day still present in page_views (not
 * just "yesterday"), so a missed cron run one day self-heals the next time
 * this runs rather than leaving a permanent gap in page_view_daily_stats.
 *
 * Deliberately does not require helpers.php: a cron hit needs no
 * session/CSRF machinery, just the DB connection from db.php.
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!defined('CRON_SAMPLE_KEY') || CRON_SAMPLE_KEY === '' || !hash_equals(CRON_SAMPLE_KEY, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = pw_db();

$rolledUp = $db->exec(
    "INSERT INTO page_view_daily_stats (stat_date, total_views, unique_visitors, member_views, guest_views)
     SELECT DATE(created_at) AS stat_date,
            COUNT(*),
            COUNT(DISTINCT visitor_id),
            SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END),
            SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END)
     FROM page_views
     WHERE created_at < UTC_DATE()
     GROUP BY DATE(created_at)
     ON DUPLICATE KEY UPDATE
       total_views = VALUES(total_views),
       unique_visitors = VALUES(unique_visitors),
       member_views = VALUES(member_views),
       guest_views = VALUES(guest_views)"
);

$pruned = $db->exec('DELETE FROM page_views WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');

echo json_encode(['ok' => true, 'days_rolled_up' => $rolledUp, 'rows_pruned' => $pruned]);
