<?php
/**
 * Cron-only daily pre-aggregation for Sankey page transitions.
 *
 * Run after 00:30 UTC so a session crossing midnight still has its next
 * page view available. The transition belongs to the UTC date of its
 * originating page view. Rebuilding the prior two days also makes a missed
 * or delayed run self-heal without rescanning the full raw retention window.
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
$adminIdsSql = "(SELECT u.id FROM users u
                 LEFT JOIN user_roles ur ON ur.user_id = u.id
                 LEFT JOIN roles r1 ON r1.slug = u.role
                 LEFT JOIN roles r2 ON r2.slug = ur.role_slug
                 WHERE r1.is_superuser = 1 OR r2.is_superuser = 1)";
$hasExistingRollups = (bool)$db->query('SELECT 1 FROM page_view_daily_transitions LIMIT 1')->fetchColumn();
$start = $hasExistingRollups ? 'UTC_DATE() - INTERVAL 2 DAY' : 'UTC_DATE() - INTERVAL 90 DAY';
$end = 'UTC_DATE()';

// Delete and rebuild both traffic variants together. The LEAD source includes
// the following day so the 30-minute midnight boundary is represented.
$db->exec("DELETE FROM page_view_daily_transitions
           WHERE stat_date >= ($start) AND stat_date < ($end)");

$sql = "INSERT INTO page_view_daily_transitions
          (stat_date, include_admin, from_path, to_path, transition_count)
        SELECT DATE(from_at), include_admin, from_path, to_path, COUNT(*)
        FROM (
          SELECT path AS from_path, created_at AS from_at, 1 AS include_admin,
                 LEAD(path) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_path,
                 LEAD(created_at) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_at
          FROM page_views
          WHERE created_at >= ($start) AND created_at < (UTC_DATE() + INTERVAL 1 DAY)
          UNION ALL
          SELECT path AS from_path, created_at AS from_at, 0 AS include_admin,
                 LEAD(path) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_path,
                 LEAD(created_at) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_at
          FROM page_views
          WHERE created_at >= ($start) AND created_at < (UTC_DATE() + INTERVAL 1 DAY)
            AND (user_id IS NULL OR user_id NOT IN $adminIdsSql)
        ) AS transitions
        WHERE from_at >= ($start) AND from_at < ($end)
          AND to_path IS NOT NULL
          AND from_path <> to_path
          AND TIMESTAMPDIFF(MINUTE, from_at, to_at) BETWEEN 0 AND 30
        GROUP BY DATE(from_at), include_admin, from_path, to_path";
$rolledUp = $db->exec($sql);

echo json_encode(['ok' => true, 'rows_written' => $rolledUp]);
