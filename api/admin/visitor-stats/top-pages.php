<?php
/**
 * "Top Pages" card: most-viewed paths within the requested window.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

$stmt = $db->prepare(
    "SELECT path, COUNT(*) AS views
     FROM page_views
     WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
     GROUP BY path
     ORDER BY views DESC
     LIMIT 10"
);
$stmt->execute([$range]);
$rows = array_map(function ($r) {
    return ['path' => $r['path'], 'views' => (int)$r['views']];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'range' => $range, 'pages' => $rows]);
