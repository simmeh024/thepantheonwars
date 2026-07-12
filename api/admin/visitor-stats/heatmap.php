<?php
/**
 * "Traffic by Day & Hour" heatmap card: unique-visitor counts grouped by
 * day-of-week and hour-of-day (both in UTC, since that's what created_at
 * is stored in -- see api/db.php). "year" bucket is bounded by however much
 * raw page_views history actually exists (pruned to a 90-day rolling
 * window by api/cron/rollup-page-views.php), since the daily rollup table
 * only tracks totals, not hour-of-day granularity.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (string)$_GET['range'] : '30';
if ($range === 'year') {
    $whereSql = 'YEAR(created_at) = YEAR(UTC_DATE())';
    $params = [];
} else {
    $days = (int)$range;
    if (!in_array($days, [7, 30, 60, 90], true)) {
        $days = 30;
    }
    $whereSql = 'created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)';
    $params = [$days];
    $range = (string)$days;
}

$includeAdmin = isset($_GET['include_admin']) && $_GET['include_admin'] === '1';
$adminFilterSql = $includeAdmin ? '1=1' : pw_admin_view_filter_sql();
$whereSql .= " AND $adminFilterSql";

$stmt = $db->prepare(
    "SELECT (DAYOFWEEK(created_at) - 1) AS weekday, HOUR(created_at) AS hour,
            COUNT(DISTINCT visitor_id) AS unique_visitors
     FROM page_views
     WHERE $whereSql
     GROUP BY weekday, hour"
);
$stmt->execute($params);

$cells = [];
$max = 0;
foreach ($stmt->fetchAll() as $r) {
    $count = (int)$r['unique_visitors'];
    $cells[] = [
        'weekday' => (int)$r['weekday'],
        'hour' => (int)$r['hour'],
        'unique_visitors' => $count,
    ];
    if ($count > $max) {
        $max = $count;
    }
}

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'max' => $max, 'cells' => $cells]);
