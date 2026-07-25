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
    // A compact Market-side readout of missions that still have crew deployed
    // or rewards pending. Launch, claim and the full mission history remain on
    // The Missions page; this endpoint only supplies the live field status.
    $ongoingStmt = $db->prepare(
        'SELECT pm.id, pm.status, pm.completes_at, md.name, md.mission_type
         FROM game_player_missions pm
         JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
         WHERE pm.user_id = ? AND pm.status IN ("active", "completed")
         ORDER BY CASE pm.status WHEN "completed" THEN 0 ELSE 1 END, pm.completes_at ASC, pm.id ASC'
    );
    $ongoingStmt->execute([(int)$user['id']]);
    $ongoingMissions = array_map(static function ($mission) {
        return [
            'id' => (int)$mission['id'],
            'name' => (string)$mission['name'],
            'mission_type' => (string)$mission['mission_type'],
            'status' => (string)$mission['status'],
            'completes_at' => (string)$mission['completes_at'],
        ];
    }, $ongoingStmt->fetchAll());
    pw_json([
        'ok' => true,
        'server_now' => pw_missions_datetime($now),
        'credits' => pw_missions_credit_balance($db, (int)$user['id']),
        'reputation' => array_merge(pw_reputation_info($points), ['level_number' => $rank]),
        'ongoing_missions' => $ongoingMissions,
        'rotations' => [
            'gear' => ['ends_at' => $rotations['gear']['window_ends_at'], 'offers' => $gear],
            'character' => ['ends_at' => $rotations['character']['window_ends_at'], 'offers' => $characters],
        ],
    ]);
} catch (Throwable $e) {
    pw_error('The Market could not establish its current rotation. Please try again.', 503);
}
