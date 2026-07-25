<?php
require_once __DIR__ . '/missions-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_missions_require_ready($db);
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

    $missions = $db->query(
        'SELECT id, world_key, name, slug, description, mission_type, duration_seconds,
                min_crew, max_crew, xp_reward, reputation_reward, sort_order
         FROM game_mission_definitions
         WHERE is_enabled = 1 AND world_key = "neoh"
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
    $missions = array_map(static function ($row) {
        foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order'] as $field) $row[$field] = (int)$row[$field];
        return $row;
    }, $missions);

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
        'missions' => $missions,
        'active_missions' => $active,
        'history' => array_slice($history, 0, 30),
    ]);
} catch (Throwable $e) {
    pw_error('Could not load your mission command view.', 500);
}
