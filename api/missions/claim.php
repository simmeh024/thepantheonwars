<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission.');
$db = pw_db();
pw_missions_require_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $missionStmt = $db->prepare(
        'SELECT pm.*, md.name AS mission_name
         FROM game_player_missions pm
         JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
         WHERE pm.id = ? AND pm.user_id = ? FOR UPDATE'
    );
    $missionStmt->execute([$missionId, $userId]);
    $mission = $missionStmt->fetch();
    if (!$mission) throw new RuntimeException('Mission not found.');
    if ($mission['status'] === 'claimed') throw new RuntimeException('This mission has already been claimed.');
    if ($mission['status'] !== 'completed') throw new RuntimeException('Complete this mission before claiming its rewards.');

    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.status
         FROM game_player_mission_crew link
         JOIN game_player_crew pc ON pc.id = link.player_crew_id
         WHERE link.player_mission_id = ? AND pc.user_id = ? FOR UPDATE'
    );
    $crewStmt->execute([$missionId, $userId]);
    $crew = $crewStmt->fetchAll();
    if (!$crew) throw new RuntimeException('This mission has no assigned crew.');
    foreach ($crew as $member) {
        if ($member['status'] !== 'on_mission') throw new RuntimeException('Crew status no longer matches this mission.');
    }

    $crewIds = array_map(static function ($member) { return (int)$member['id']; }, $crew);
    $placeholders = pw_missions_placeholders(count($crewIds));
    $crewUpdate = $db->prepare(
        'UPDATE game_player_crew SET xp = xp + ?, status = "available"
         WHERE user_id = ? AND id IN (' . $placeholders . ') AND status = "on_mission"'
    );
    $crewUpdate->execute(array_merge([(int)$mission['xp_reward'], $userId], $crewIds));
    if ($crewUpdate->rowCount() !== count($crewIds)) throw new RuntimeException('Crew status no longer matches this mission.');

    $reputationAwarded = pw_award_reputation(
        $db,
        $userId,
        (int)$mission['reputation_reward'],
        'mission_completed',
        [
            'label' => 'Mission: ' . $mission['mission_name'],
            'source_type' => 'mission',
            'source_id' => $missionId,
            'note' => 'Neoh expedition reward',
        ]
    );
    $now = pw_missions_utc_now($db);
    $missionUpdate = $db->prepare('UPDATE game_player_missions SET status = "claimed", claimed_at = ? WHERE id = ? AND status = "completed"');
    $missionUpdate->execute([pw_missions_datetime($now), $missionId]);
    if ($missionUpdate->rowCount() !== 1) throw new RuntimeException('This mission reward was already claimed.');
    $db->commit();
    pw_json([
        'ok' => true,
        'xp_awarded_per_crew' => (int)$mission['xp_reward'],
        'reputation_awarded' => $reputationAwarded,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not claim mission rewards. Please try again.', 409);
}
