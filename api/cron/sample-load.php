<?php
/**
 * Cron-only endpoint: samples this shared host's load average once a
 * minute into cpu_load_history, backing the "CPU (Shared)" card's 24h line
 * chart on the System Status page. A single web request can only ever see
 * a single instant of load -- a real trend (and a DDoS-style spike) needs
 * repeated samples over time, which is what this accumulates.
 *
 * Invoked by a cPanel Cron Job (see cron setup notes) hitting this URL with
 * ?key=<CRON_SAMPLE_KEY> once a minute via curl/wget. Gated by a shared
 * secret since api/ is publicly reachable and this shouldn't be
 * triggerable (or spammable) by random requests. CRON_SAMPLE_KEY is defined
 * in the outside-webroot secrets file (see db.php / config.sample.php for
 * where that lives) -- it is NOT committed to git.
 *
 * Deliberately does not require helpers.php: a cron hit needs no
 * session/CSRF machinery, just the DB connection from db.php.
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!defined('CRON_SAMPLE_KEY') || CRON_SAMPLE_KEY === '' || !hash_equals(CRON_SAMPLE_KEY, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (!function_exists('sys_getloadavg')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'sys_getloadavg unavailable on this host']);
    exit;
}

$load = sys_getloadavg();
if (!$load) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to read load average']);
    exit;
}

$db = pw_db();
$stmt = $db->prepare(
    'INSERT INTO cpu_load_history (load1, load5, load15, recorded_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
);
$stmt->execute([$load[0], $load[1], $load[2]]);

// Keep the table trimmed to ~25h of history -- the chart only ever shows
// the last 24h, so anything older than that is dead weight. Cheap enough
// to run on every sample rather than as a separate scheduled job.
$db->exec('DELETE FROM cpu_load_history WHERE recorded_at < (UTC_TIMESTAMP() - INTERVAL 25 HOUR)');

echo json_encode(['ok' => true, 'load1' => $load[0], 'load5' => $load[1], 'load15' => $load[2]]);
