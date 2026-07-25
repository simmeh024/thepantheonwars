<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission.');
$crewIds = pw_missions_normalize_crew_ids($input['crew_ids'] ?? null);
$db = pw_db();
pw_missions_require_successions_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    pw_missions_grant_starter_crew($db, $userId);

    $missionStmt = $db->prepare('SELECT * FROM game_mission_definitions WHERE id = ? FOR UPDATE');
    $missionStmt->execute([$missionId]);
    $mission = $missionStmt->fetch();
    if (!$mission || !(bool)$mission['is_enabled'] || $mission['world_key'] !== 'neoh') {
        throw new RuntimeException('That mission is no longer available.');
    }
    if ($mission['unlocks_after_mission_id'] !== null) {
        $requiredCompletions = max(1, (int)$mission['unlocks_after_completion_count']);
        $completedStmt = $db->prepare(
            'SELECT COUNT(*) FROM game_player_missions
             WHERE user_id = ? AND mission_definition_id = ? AND status = "claimed"'
        );
        $completedStmt->execute([$userId, (int)$mission['unlocks_after_mission_id']]);
        $completedCount = (int)$completedStmt->fetchColumn();
        if ($completedCount < $requiredCompletions) {
            $prerequisiteStmt = $db->prepare('SELECT name FROM game_mission_definitions WHERE id = ?');
            $prerequisiteStmt->execute([(int)$mission['unlocks_after_mission_id']]);
            $prerequisite = $prerequisiteStmt->fetch();
            $remaining = $requiredCompletions - $completedCount;
            $name = $prerequisite ? $prerequisite['name'] : 'the prerequisite mission';
            throw new RuntimeException('Complete ' . $name . ' ' . $remaining . ' more ' . ($remaining === 1 ? 'time' : 'times') . ' to unlock this mission.');
        }
    }
    if (count($crewIds) < (int)$mission['min_crew'] || count($crewIds) > (int)$mission['max_crew']) {
        throw new RuntimeException('This mission requires between ' . (int)$mission['min_crew'] . ' and ' . (int)$mission['max_crew'] . ' crew members.');
    }

    $statsReady = pw_mission_stats_ready($db);
    $placeholders = pw_missions_placeholders(count($crewIds));
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.status, pc.level, c.role'
        . ($statsReady ? ', pc.strength, pc.cunning, pc.science, pc.charisma' : '') .
        ' FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         WHERE pc.user_id = ? AND pc.id IN (' . $placeholders . ') FOR UPDATE'
    );
    $crewStmt->execute(array_merge([$userId], $crewIds));
    $selectedCrew = $crewStmt->fetchAll();
    if (count($selectedCrew) !== count($crewIds)) throw new RuntimeException('One selected crew member does not belong to you.');
    foreach ($selectedCrew as $member) {
        if ($member['status'] !== 'available') throw new RuntimeException('Every selected crew member must be available.');
    }

    /* The Engineer bonus is applied to the completion time at launch, so the
     * countdown a player watches is the real one. Every other bonus is resolved
     * at claim instead -- a crew that levels up mid-mission should benefit,
     * and the duration is already fixed by then. */
    $effects = $statsReady
        ? pw_missions_crew_effects($selectedCrew)
        : ['duration_percent' => 0.0, 'success_percent' => 0.0];
    $duration = pw_missions_effective_duration((int)$mission['duration_seconds'], $effects);

    $now = pw_missions_utc_now($db);
    $completesAt = $now->modify('+' . $duration . ' seconds');
    $insert = $db->prepare(
        'INSERT INTO game_player_missions
         (user_id, mission_definition_id, world_key, status, started_at, completes_at, xp_reward, reputation_reward)
         VALUES (?, ?, ?, "active", ?, ?, ?, ?)'
    );
    $insert->execute([
        $userId, (int)$mission['id'], $mission['world_key'], pw_missions_datetime($now), pw_missions_datetime($completesAt),
        (int)$mission['xp_reward'], (int)$mission['reputation_reward'],
    ]);
    $playerMissionId = (int)$db->lastInsertId();
    $linkStmt = $db->prepare('INSERT INTO game_player_mission_crew (player_mission_id, player_crew_id) VALUES (?, ?)');
    foreach ($crewIds as $crewId) $linkStmt->execute([$playerMissionId, $crewId]);

    $statusUpdate = $db->prepare(
        'UPDATE game_player_crew SET status = "on_mission"
         WHERE user_id = ? AND status = "available" AND id IN (' . $placeholders . ')'
    );
    $statusUpdate->execute(array_merge([$userId], $crewIds));
    if ($statusUpdate->rowCount() !== count($crewIds)) {
        throw new RuntimeException('Crew availability changed while the mission was launching.');
    }
    $db->commit();
    pw_json(['ok' => true, 'mission_id' => $playerMissionId, 'completes_at' => pw_missions_datetime($completesAt)]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not launch this mission. Please try again.', 409);
}
