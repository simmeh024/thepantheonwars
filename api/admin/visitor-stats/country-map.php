<?php
/**
 * All countries with any traffic in the window (no LIMIT), for coloring the
 * vendored world map (images/vendor/world-map.svg, ISO 3166-1 alpha-2 path
 * ids, lowercase) rather than just a top-10 ranked list. Same underlying
 * data as top-countries.php; kept as a separate endpoint so that one's
 * existing top-10 contract for the ranked-list view never changes.
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
     WHERE country_code IS NOT NULL AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
     GROUP BY country_code, country_name
     ORDER BY views DESC"
);
$stmt->execute([$range]);
$rows = array_map(function ($r) {
    return [
        // Lowercased to match the vendored SVG's path ids directly.
        'country_code' => strtolower((string)$r['country_code']),
        'country_name' => $r['country_name'] ?: $r['country_code'],
        'views' => (int)$r['views'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'countries' => $rows]);
