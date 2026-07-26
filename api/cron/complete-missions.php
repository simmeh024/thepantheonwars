<?php
/**
 * Cron-only endpoint: settles every mission run whose completion time has
 * passed and notifies the player that it is waiting.
 *
 * Runs used to reach "completed" only when the player's open browser tab
 * posted to api/missions/complete.php from its one-second countdown, so a
 * finished operation sat at "active" for as long as the player stayed away --
 * and nothing ever told them it was ready. api/missions/overview.php settles
 * the current player's runs on load, which keeps the page correct, but only
 * this sweep can reach a player who is not looking at it.
 *
 * Invoked by a cPanel Cron Job hitting this URL with ?key=<CRON_SAMPLE_KEY>.
 * Every five minutes is enough: the notification is the point, and the reward
 * is unaffected by when the row is settled -- claim.php independently treats a
 * due run as complete. Reuses CRON_SAMPLE_KEY rather than adding a fourth
 * secret for the same trust boundary, as the quality-report cron already does.
 *
 * Unlike the other crons this one does require helpers.php, because sending a
 * notification needs pw_notify() and the preference check behind it.
 */
require_once __DIR__ . '/../missions/missions-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!defined('CRON_SAMPLE_KEY') || CRON_SAMPLE_KEY === '' || !hash_equals(CRON_SAMPLE_KEY, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = pw_db();
if (!pw_missions_ready($db)) {
    // Not an error: the mission migrations are optional and this sweep simply
    // has nothing to do until they have been run.
    echo json_encode(['ok' => true, 'settled' => 0, 'skipped' => 'missions unavailable']);
    exit;
}

echo json_encode(['ok' => true, 'settled' => pw_missions_settle_due_runs($db)]);
