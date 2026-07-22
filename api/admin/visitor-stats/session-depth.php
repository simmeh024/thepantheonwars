<?php
/**
 * "Bounce Rate & Session Depth" trend. A simplified per-UTC-day proxy for
 * true timeout-based sessionization (which would need a session-boundary
 * definition this codebase doesn't otherwise track): a "session" here is
 * one visitor's page views within a single UTC day. Bounce rate is the
 * share of visitor-days with exactly one page view; session depth is the
 * average page views per visitor-day. Computed directly from raw page_views
 * for the requested window rather than a cron rollup -- if this proves slow
 * at scale, converting it into a daily rollup (matching page_view_daily_stats)
 * would be the next step, not a redesign of the metric itself.
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
    "SELECT stat_date,
            SUM(views_per_visitor) AS total_views,
            COUNT(*) AS visitor_days,
            SUM(views_per_visitor = 1) AS bounces
     FROM (
         SELECT DATE(created_at) AS stat_date, visitor_id, COUNT(*) AS views_per_visitor
         FROM page_views
         WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
         GROUP BY DATE(created_at), visitor_id
     ) t
     GROUP BY stat_date
     ORDER BY stat_date ASC"
);
$stmt->execute([$range]);

$points = array_map(function ($r) {
    $visitorDays = (int)$r['visitor_days'];
    return [
        'date' => $r['stat_date'],
        'avg_session_depth' => $visitorDays > 0 ? round((int)$r['total_views'] / $visitorDays, 2) : 0.0,
        'bounce_rate' => $visitorDays > 0 ? round((int)$r['bounces'] / $visitorDays * 100, 1) : 0.0,
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'points' => $points]);
