<?php
/**
 * "Top Referrers" card: external hostnames sending traffic within the
 * requested window. Rows with no referrer_host (direct navigation, or a
 * referrer whose host matches this site itself) are bucketed together as
 * "Direct / Internal" rather than shown as a blank label.
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
    "SELECT
        CASE
            WHEN referrer_host IS NULL OR referrer_host = '' OR referrer_host = 'thepantheonwars.com'
                THEN NULL
            ELSE referrer_host
        END AS host,
        COUNT(*) AS views
     FROM page_views
     WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
     GROUP BY host
     ORDER BY views DESC
     LIMIT 10"
);
$stmt->execute([$range]);
$rows = array_map(function ($r) {
    return ['host' => $r['host'] !== null ? $r['host'] : 'Direct / Internal', 'views' => (int)$r['views']];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'include_admin' => $includeAdmin, 'referrers' => $rows]);
