<?php
require_once __DIR__ . '/missions-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_missions_require_successions_ready($db);
$userId = (int)$user['id'];

try {
    pw_missions_grant_starter_crew($db, $userId);

    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.level, pc.xp, pc.status, pc.created_at,
                c.name, c.slug, c.description, c.role, c.portrait_url, c.world_affinity, c.is_enabled AS definition_enabled,
                active.id AS active_mission_id, active.status AS active_mission_status,
                active.completes_at AS active_mission_completes_at, active.active_mission_name
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
         LEFT JOIN (
             SELECT link.player_crew_id, pm.id, pm.status, pm.completes_at, md.name AS active_mission_name
             FROM game_player_mission_crew link
             JOIN game_player_missions pm ON pm.id = link.player_mission_id
             JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
             WHERE pm.user_id = ? AND pm.status IN ("active", "completed")
         ) active ON active.player_crew_id = pc.id
         WHERE pc.user_id = ?
         ORDER BY c.is_starter DESC, c.role ASC, c.name ASC'
    );
    $crewStmt->execute([$userId, $userId]);
    $crew = array_map(static function ($row) {
        foreach (['id', 'level', 'xp'] as $field) $row[$field] = (int)$row[$field];
        $row['definition_enabled'] = (bool)$row['definition_enabled'];
        $row['active_mission_id'] = $row['active_mission_id'] !== null ? (int)$row['active_mission_id'] : null;
        return $row;
    }, $crewStmt->fetchAll());

    $claimedStmt = $db->prepare(
        'SELECT mission_definition_id, COUNT(*) AS claimed_count
         FROM game_player_missions
         WHERE user_id = ? AND status = "claimed"
         GROUP BY mission_definition_id'
    );
    $claimedStmt->execute([$userId]);
    $claimedCounts = [];
    foreach ($claimedStmt->fetchAll() as $row) {
        $claimedCounts[(int)$row['mission_definition_id']] = (int)$row['claimed_count'];
    }

    /* The whole world is loaded, including disabled missions, so the campaign
     * chain keeps its real length even while a mid-chain operation is switched
     * off in Mission Control. Card rendering filters to enabled missions below. */
    $campaignReady = pw_mission_campaign_ready($db);
    $missionsStmt = $db->prepare(
        'SELECT mission.id, mission.world_key, mission.name, mission.slug, mission.description, mission.mission_type, mission.duration_seconds,
                mission.min_crew, mission.max_crew, mission.xp_reward, mission.reputation_reward, mission.sort_order, mission.is_enabled,
                mission.unlocks_after_mission_id, mission.unlocks_after_completion_count,'
        . ($campaignReady ? ' mission.is_campaign_final,' : ' 0 AS is_campaign_final,') .
               ' prerequisite.name AS unlocks_after_mission_name
         FROM game_mission_definitions mission
         LEFT JOIN game_mission_definitions prerequisite ON prerequisite.id = mission.unlocks_after_mission_id
         WHERE mission.world_key = "neoh"
         ORDER BY mission.sort_order ASC, mission.id ASC'
    );
    $missionsStmt->execute();
    $worldMissions = array_map(static function ($row) use ($claimedCounts) {
        foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order', 'unlocks_after_completion_count'] as $field) $row[$field] = (int)$row[$field];
        $row['unlocks_after_mission_id'] = $row['unlocks_after_mission_id'] !== null ? (int)$row['unlocks_after_mission_id'] : null;
        $row['is_enabled'] = (bool)$row['is_enabled'];
        $row['is_campaign_final'] = (bool)$row['is_campaign_final'];
        if ($row['unlocks_after_mission_id'] !== null) {
            $row['unlocks_after_completion_count'] = max(1, $row['unlocks_after_completion_count']);
        }
        $row['is_unlocked'] = $row['unlocks_after_mission_id'] === null
            || ($claimedCounts[$row['unlocks_after_mission_id']] ?? 0) >= $row['unlocks_after_completion_count'];
        return $row;
    }, $missionsStmt->fetchAll());

    $missionsById = [];
    foreach ($worldMissions as $mission) $missionsById[(int)$mission['id']] = $mission;
    $campaign = pw_missions_campaign_progress($missionsById, $claimedCounts);

    /* A locked mission is omitted from the response entirely -- name, slug,
     * description, rewards and crew requirements included. Sending it and
     * hiding it in CSS would hand every unreleased operation to anyone opening
     * the network tab, the same reason api/timeline.php seals a gated event
     * server-side rather than dimming it in the browser. The campaign bar above
     * is the only thing that acknowledges those missions exist. */
    $missions = array_values(array_map(static function ($mission) {
        unset($mission['is_enabled'], $mission['is_campaign_final'], $mission['is_unlocked']);
        return $mission;
    }, array_filter($worldMissions, static function ($mission) {
        return $mission['is_enabled'] && $mission['is_unlocked'];
    })));

    $playerMissionStmt = $db->prepare(
        'SELECT pm.id, pm.world_key, pm.status, pm.started_at, pm.completes_at, pm.completed_at, pm.claimed_at,
                pm.xp_reward, pm.reputation_reward, md.name, md.slug, md.mission_type,
                (pm.completes_at <= UTC_TIMESTAMP()) AS is_ready,
                GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR "|~|") AS crew_names
         FROM game_player_missions pm
         JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
         LEFT JOIN game_player_mission_crew link ON link.player_mission_id = pm.id
         LEFT JOIN game_player_crew pc ON pc.id = link.player_crew_id
         LEFT JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
         WHERE pm.user_id = ?
         GROUP BY pm.id
         ORDER BY CASE pm.status WHEN "active" THEN 0 WHEN "completed" THEN 1 ELSE 2 END, pm.started_at DESC, pm.id DESC'
    );
    $playerMissionStmt->execute([$userId]);
    $allPlayerMissions = array_map(static function ($row) {
        $row['id'] = (int)$row['id'];
        $row['xp_reward'] = (int)$row['xp_reward'];
        $row['reputation_reward'] = (int)$row['reputation_reward'];
        $row['is_ready'] = (bool)$row['is_ready'];
        $row['crew_names'] = $row['crew_names'] !== null && $row['crew_names'] !== '' ? explode('|~|', $row['crew_names']) : [];
        return $row;
    }, $playerMissionStmt->fetchAll());
    $active = array_values(array_filter($allPlayerMissions, static function ($mission) {
        return in_array($mission['status'], ['active', 'completed'], true);
    }));
    $history = array_values(array_filter($allPlayerMissions, static function ($mission) {
        return $mission['status'] === 'claimed';
    }));

    $availableCrew = count(array_filter($crew, static function ($member) { return $member['status'] === 'available'; }));
    $serverTime = $db->query('SELECT UTC_TIMESTAMP() AS value')->fetch();
    pw_json([
        'ok' => true,
        'world' => ['key' => 'neoh', 'name' => 'Neoh', 'background' => 'images/world-neoh.jpg'],
        'server_time' => $serverTime['value'],
        'stats' => [
            'active_missions' => count($active),
            'available_crew' => $availableCrew,
            'completed_missions' => count($history),
            'total_missions' => count($allPlayerMissions),
        ],
        'crew' => $crew,
        'campaign' => $campaign,
        'missions' => $missions,
        'active_missions' => $active,
        'history' => array_slice($history, 0, 30),
    ]);
} catch (Throwable $e) {
    pw_error('Could not load your mission command view.', 500);
}
