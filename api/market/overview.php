<?php
require_once __DIR__ . '/market-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_market_require_ready($db);

try {
    $userStmt = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $userStmt->execute([(int)$user['id']]);
    $points = (int)$userStmt->fetchColumn();
    $now = pw_missions_utc_now($db);
    $rotations = pw_market_current_rotations($db, $now);
    $rank = pw_market_reputation_level($points);
    $gear = pw_market_public_offers($db, (int)$rotations['gear']['id'], 'gear', $rank);
    $characters = pw_market_public_offers($db, (int)$rotations['character']['id'], 'character', $rank);
    pw_json([
        'ok' => true,
        'server_now' => pw_missions_datetime($now),
        'credits' => pw_missions_credit_balance($db, (int)$user['id']),
        'reputation' => array_merge(pw_reputation_info($points), ['level_number' => $rank]),
        'rotations' => [
            'gear' => ['ends_at' => $rotations['gear']['window_ends_at'], 'offers' => $gear],
            'character' => ['ends_at' => $rotations['character']['window_ends_at'], 'offers' => $characters],
        ],
    ]);
} catch (Throwable $e) {
    pw_error('The Market could not establish its current rotation. Please try again.', 503);
}
