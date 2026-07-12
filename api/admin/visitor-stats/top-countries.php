<?php
/**
 * "Traffic by Country" card: page views grouped by resolved country within
 * the requested window. Rows with no resolved country (private/invalid IP,
 * or a lookup that failed/hasn't happened -- see pw_resolve_country()) are
 * bucketed together as "Unknown" rather than shown blank.
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
    "SELECT country_code, country_name, COUNT(*) AS views
     FROM page_views
     WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
     GROUP BY country_code, country_name
     ORDER BY views DESC
     LIMIT 10"
);
$stmt->execute([$range]);
$rows = array_map(function ($r) {
    return [
        'country_code' => $r['country_code'],
        'country_name' => $r['country_name'] ?: 'Unknown',
        'views' => (int)$r['views'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'countries' => $rows]);
