<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/loc-stats-helpers.php';

pw_require_permission('dashboards.view_home');

$db = pw_db();
$loc = pw_get_loc_stats($db);

pw_json(array_merge(
    ['ok' => true],
    pw_get_delivery_7d_stats($db, $loc ? $loc['total_lines'] : null)
));
