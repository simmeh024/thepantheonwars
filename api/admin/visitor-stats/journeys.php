<?php
/**
 * Consecutive page transitions for the Visitor Statistics Sankey diagram.
 *
 * Finished days are served from page_view_daily_transitions, which the daily
 * cron pre-aggregates. Only the current UTC day is calculated from raw rows,
 * keeping the expensive window function bounded as traffic grows.
 *
 * A transition only counts when the next tracked view for the same visitor
 * cookie occurs within 30 minutes. This prevents a return visit days later
 * from being presented as one continuous journey. Repeated views of the same
 * path are left out: they add noise to a between-pages flow diagram without
 * showing a navigation choice.
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

$startDate = gmdate('Y-m-d', time() - ($range * 86400));
$today = gmdate('Y-m-d');
$counts = [];
$useRollups = false;
try {
    $rollupStmt = $db->prepare(
        "SELECT from_path, to_path, SUM(transition_count) AS transitions
         FROM page_view_daily_transitions
         WHERE include_admin = ?
           AND stat_date >= ?
           AND stat_date < ?
         GROUP BY from_path, to_path"
    );
    $rollupStmt->execute([$includeAdmin ? 1 : 0, $startDate, $today]);
    $rollupRows = $rollupStmt->fetchAll();
    $useRollups = !empty($rollupRows);
    foreach ($rollupRows as $row) {
        $key = $row['from_path'] . "\0" . $row['to_path'];
        $counts[$key] = [
            'from' => $row['from_path'],
            'to' => $row['to_path'],
            'count' => (int)$row['transitions'],
        ];
    }
} catch (PDOException $e) {
    // The migration may not have run yet; keep the old raw-query behavior.
}

$rawSinceSql = $useRollups ? 'UTC_DATE()' : '(UTC_TIMESTAMP() - INTERVAL ? DAY)';
$rawParams = $useRollups ? [] : [$range];
$rawStmt = $db->prepare(
    "SELECT from_path, to_path, COUNT(*) AS transitions
     FROM (
       SELECT path AS from_path,
              created_at AS from_at,
              LEAD(path) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_path,
              LEAD(created_at) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_at
       FROM page_views
       WHERE created_at >= $rawSinceSql AND $adminFilterSql
     ) AS visit_sequence
     WHERE to_path IS NOT NULL
       AND from_path <> to_path
       AND TIMESTAMPDIFF(MINUTE, from_at, to_at) BETWEEN 0 AND 30
     GROUP BY from_path, to_path
     ORDER BY transitions DESC, from_path ASC, to_path ASC
     LIMIT 24"
);
$rawStmt->execute($rawParams);

foreach ($rawStmt->fetchAll() as $row) {
    $key = $row['from_path'] . "\0" . $row['to_path'];
    if (!isset($counts[$key])) {
        $counts[$key] = ['from' => $row['from_path'], 'to' => $row['to_path'], 'count' => 0];
    }
    $counts[$key]['count'] += (int)$row['transitions'];
}

$transitions = array_values($counts);
usort($transitions, function ($a, $b) {
    return $b['count'] <=> $a['count']
        ?: strcmp($a['from'], $b['from'])
        ?: strcmp($a['to'], $b['to']);
});
$transitions = array_slice($transitions, 0, 24);

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'transitions' => $transitions]);
