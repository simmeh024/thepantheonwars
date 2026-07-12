<?php
/**
 * Consecutive page transitions for the Visitor Statistics Sankey diagram.
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

$stmt = $db->prepare(
    "SELECT from_path, to_path, COUNT(*) AS transitions
     FROM (
       SELECT path AS from_path,
              created_at AS from_at,
              LEAD(path) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_path,
              LEAD(created_at) OVER (PARTITION BY visitor_id ORDER BY created_at, id) AS to_at
       FROM page_views
       WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
     ) AS visit_sequence
     WHERE to_path IS NOT NULL
       AND from_path <> to_path
       AND TIMESTAMPDIFF(MINUTE, from_at, to_at) BETWEEN 0 AND 30
     GROUP BY from_path, to_path
     ORDER BY transitions DESC, from_path ASC, to_path ASC
     LIMIT 24"
);
$stmt->execute([$range]);

$transitions = array_map(function ($row) {
    return [
        'from' => $row['from_path'],
        'to' => $row['to_path'],
        'count' => (int)$row['transitions'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'transitions' => $transitions]);
