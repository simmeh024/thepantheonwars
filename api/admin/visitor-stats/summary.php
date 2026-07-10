<?php
/**
 * Traffic Snapshot card on the Visitor Statistics admin page: today/7d/30d
 * page-view totals, unique visitors (distinct pw_vid cookie values), and
 * the member-vs-guest split for each window.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

function pw_visitor_stats_window($db, $sinceSql) {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT visitor_id) AS unique_visitors,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS member_views,
                SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS guest_views
         FROM page_views
         WHERE created_at >= $sinceSql"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    return [
        'total_views' => (int)$row['total'],
        'unique_visitors' => (int)$row['unique_visitors'],
        'member_views' => (int)$row['member_views'],
        'guest_views' => (int)$row['guest_views'],
    ];
}

pw_json([
    'ok' => true,
    'today' => pw_visitor_stats_window($db, 'UTC_DATE()'),
    'last_7_days' => pw_visitor_stats_window($db, '(UTC_TIMESTAMP() - INTERVAL 7 DAY)'),
    'last_30_days' => pw_visitor_stats_window($db, '(UTC_TIMESTAMP() - INTERVAL 30 DAY)'),
]);
