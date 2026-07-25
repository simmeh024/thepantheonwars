<?php
/**
 * Claim today's daily objective reward.
 *
 * The objective and its reward are both resolved here from the server's own
 * catalogue and the server's own counters -- the client sends nothing but its
 * CSRF token, so it cannot name an objective, a reward or an amount.
 */
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_dailies_ready($db)) {
    pw_error('Daily objectives are being prepared. Please try again after the Mission Dailies migration has been run.', 503);
}
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $date = pw_missions_daily_date($db);
    $daily = pw_missions_daily_for_user($userId, $date);

    $progressStmt = $db->prepare('SELECT progress FROM game_player_daily_progress WHERE user_id = ? AND stat_date = ? AND metric_key = ? FOR UPDATE');
    $progressStmt->execute([$userId, $date, $daily['metric']]);
    $progress = (int)($progressStmt->fetchColumn() ?: 0);
    if ($progress < $daily['target']) throw new RuntimeException('This objective is not complete yet.');

    /* The primary key is the real guard against a double claim: whichever
     * request inserts first has been paid, and the other sees the collision.
     * Checking first and inserting after would leave a window between them. */
    $claim = $db->prepare('INSERT IGNORE INTO game_player_daily_claims (user_id, stat_date, daily_key, reward_type, reward_amount) VALUES (?, ?, ?, ?, ?)');
    $claim->execute([$userId, $date, $daily['key'], $daily['reward_type'], $daily['reward_amount']]);
    if ($claim->rowCount() < 1) throw new RuntimeException('Today\'s objective reward has already been claimed.');

    $reputationAwarded = 0;
    $creditsAwarded = 0;
    $creditsTotal = 0;
    if ($daily['reward_type'] === 'reputation') {
        $reputationAwarded = pw_award_reputation($db, $userId, (int)$daily['reward_amount'], 'mission_daily', [
            'label' => 'Daily objective: ' . $daily['label'],
            'source_type' => 'mission_daily',
            'note' => $date,
        ]);
    } elseif ($daily['reward_type'] === 'credits') {
        if (!pw_mission_credits_ready($db)) throw new RuntimeException('Credits are not available yet. Please try again shortly.');
        $creditsAwarded = (int)$daily['reward_amount'];
        $creditsTotal = pw_missions_add_credits($db, $userId, $creditsAwarded);
    }

    $db->commit();
    pw_json([
        'ok' => true,
        'label' => $daily['label'],
        'reward_type' => $daily['reward_type'],
        'reputation_awarded' => $reputationAwarded,
        'credits_awarded' => $creditsAwarded,
        'credits_total' => $creditsTotal,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not claim this objective reward. Please try again.', 409);
}
