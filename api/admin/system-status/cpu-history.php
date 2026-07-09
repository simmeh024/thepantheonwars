<?php
/**
 * Feeds the "CPU (Shared)" card's 24h line chart on the expanded System
 * Status page. Returns every sample cpu_load_history has from the last 24h
 * (one row/minute, written by the cron sampler -- see
 * api/cron/sample-load.php) plus the live current load average as of this
 * exact request, so the "current" numbers never lag behind the last stored
 * sample by up to a minute.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

pw_require_admin();
$db = pw_db();

$points = [];
try {
    $rows = $db->query(
        "SELECT load1, recorded_at FROM cpu_load_history
         WHERE recorded_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
         ORDER BY recorded_at ASC"
    )->fetchAll();
    foreach ($rows as $row) {
        $points[] = [
            't' => gmdate('c', strtotime($row['recorded_at'] . ' UTC')),
            'load1' => (float)$row['load1'],
        ];
    }
} catch (Exception $e) {
    // cpu_load_history not migrated in yet on this environment -- return an
    // empty series rather than erroring the whole page out.
}

pw_json([
    'ok' => true,
    'points' => $points,
    'current' => pw_check_cpu_load(),
]);
