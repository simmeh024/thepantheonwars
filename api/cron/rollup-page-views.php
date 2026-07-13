<?php
/**
 * Cron-only endpoint: rolls yesterday's raw page_views rows up into one
 * page_view_daily_stats row, then prunes page_views back to a 90-day rolling
 * window. Invoked once/day by a cPanel Cron Job hitting
 * this URL with ?key=<CRON_SAMPLE_KEY> (the same shared secret that gates
 * api/cron/sample-load.php -- both are cron-only, publicly-reachable
 * endpoints with the same trust boundary, so one secret covers both).
 *
 * Normal runs only touch the completed UTC day, keeping the daily job small
 * as raw traffic grows. Pass ?full=1 alongside the cron key to deliberately
 * rebuild every finished day in the retained raw history after a repair or a
 * missed period.
 *
 * Deliberately does not require helpers.php: a cron hit needs no
 * session/CSRF machinery, just the DB connection from db.php.
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!defined('CRON_SAMPLE_KEY') || CRON_SAMPLE_KEY === '' || !hash_equals(CRON_SAMPLE_KEY, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = pw_db();
$fullRebuild = isset($_GET['full']) && $_GET['full'] === '1';
$rollupWhere = $fullRebuild
    ? 'created_at < UTC_DATE()'
    : 'created_at >= (UTC_DATE() - INTERVAL 1 DAY) AND created_at < UTC_DATE()';

// admin_ids: superuser (any is_superuser role, not just the literal 'admin'
// slug) user ids, computed once and reused in the two admin-excluded
// aggregates below -- same definition as pw_admin_view_filter_sql() in
// helpers.php, duplicated here since this cron endpoint deliberately
// doesn't load helpers.php (see file-level comment).
$adminIdsSql = "(SELECT u.id FROM users u
                 LEFT JOIN user_roles ur ON ur.user_id = u.id
                 LEFT JOIN roles r1 ON r1.slug = u.role
                 LEFT JOIN roles r2 ON r2.slug = ur.role_slug
                 WHERE r1.is_superuser = 1 OR r2.is_superuser = 1)";

$rolledUp = $db->exec(
    "INSERT INTO page_view_daily_stats (
        stat_date, total_views, unique_visitors, member_views, guest_views,
        total_views_excl_admin, unique_visitors_excl_admin, member_views_excl_admin
     )
     SELECT DATE(created_at) AS stat_date,
            COUNT(*),
            COUNT(DISTINCT visitor_id),
            SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END),
            SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END),
            SUM(CASE WHEN user_id IS NULL OR user_id NOT IN $adminIdsSql THEN 1 ELSE 0 END),
            COUNT(DISTINCT CASE WHEN user_id IS NULL OR user_id NOT IN $adminIdsSql THEN visitor_id END),
            SUM(CASE WHEN user_id IS NOT NULL AND user_id NOT IN $adminIdsSql THEN 1 ELSE 0 END)
     FROM page_views
     WHERE $rollupWhere
     GROUP BY DATE(created_at)
     ON DUPLICATE KEY UPDATE
       total_views = VALUES(total_views),
       unique_visitors = VALUES(unique_visitors),
       member_views = VALUES(member_views),
       guest_views = VALUES(guest_views),
       total_views_excl_admin = VALUES(total_views_excl_admin),
       unique_visitors_excl_admin = VALUES(unique_visitors_excl_admin),
       member_views_excl_admin = VALUES(member_views_excl_admin)"
);

$pruned = $db->exec('DELETE FROM page_views WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');

echo json_encode([
    'ok' => true,
    'mode' => $fullRebuild ? 'full' : 'daily',
    'days_rolled_up' => $rolledUp,
    'rows_pruned' => $pruned,
]);
