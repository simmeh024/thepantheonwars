<?php
/**
 * Daily series backing the "Visits Over Time" chart. Reads the permanent
 * page_view_daily_stats rollup (written once/day by
 * api/cron/rollup-page-views.php) for every finished day in the requested
 * window, then appends a live count from the raw page_views table for
 * today's still-in-progress bucket so the chart's last point isn't stale.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

$stmt = $db->prepare(
    "SELECT stat_date, total_views, unique_visitors, member_views, guest_views
     FROM page_view_daily_stats
     WHERE stat_date >= (UTC_DATE() - INTERVAL ? DAY) AND stat_date < UTC_DATE()
     ORDER BY stat_date ASC"
);
$stmt->execute([$range - 1]);
$points = array_map(function ($r) {
    return [
        'date' => $r['stat_date'],
        'total_views' => (int)$r['total_views'],
        'unique_visitors' => (int)$r['unique_visitors'],
        'member_views' => (int)$r['member_views'],
        'guest_views' => (int)$r['guest_views'],
    ];
}, $stmt->fetchAll());

$todayStmt = $db->prepare(
    "SELECT COUNT(*) AS total,
            COUNT(DISTINCT visitor_id) AS unique_visitors,
            SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS member_views,
            SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS guest_views
     FROM page_views
     WHERE created_at >= UTC_DATE()"
);
$todayStmt->execute();
$today = $todayStmt->fetch();
$points[] = [
    'date' => gmdate('Y-m-d'),
    'total_views' => (int)$today['total'],
    'unique_visitors' => (int)$today['unique_visitors'],
    'member_views' => (int)$today['member_views'],
    'guest_views' => (int)$today['guest_views'],
];

pw_json(['ok' => true, 'range' => $range, 'points' => $points]);
