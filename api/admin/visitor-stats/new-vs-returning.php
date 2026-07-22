<?php
/**
 * "New vs Returning Visitors" trend. A visitor is "new" on a given UTC day
 * if they have no page_views row before that day anywhere in history (not
 * just within the requested window -- a visitor who first appeared months
 * ago and returns today is still "returning", even if that first visit
 * falls outside the chart's own date range).
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

$includeAdmin = isset($_GET['include_admin']) && $_GET['include_admin'] === '1';
$adminFilterSql = $includeAdmin ? '1=1' : pw_admin_view_filter_sql();

$stmt = $db->prepare(
    "SELECT d.stat_date,
            COUNT(*) AS total_visitors,
            SUM(NOT EXISTS (
                SELECT 1 FROM page_views p2
                WHERE p2.visitor_id = d.visitor_id AND p2.created_at < d.stat_date
            )) AS new_visitors
     FROM (
         SELECT DISTINCT DATE(created_at) AS stat_date, visitor_id
         FROM page_views
         WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
     ) d
     GROUP BY d.stat_date
     ORDER BY d.stat_date ASC"
);
$stmt->execute([$range]);

$points = array_map(function ($r) {
    $total = (int)$r['total_visitors'];
    $new = (int)$r['new_visitors'];
    return [
        'date' => $r['stat_date'],
        'new_visitors' => $new,
        'returning_visitors' => $total - $new,
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'points' => $points]);
