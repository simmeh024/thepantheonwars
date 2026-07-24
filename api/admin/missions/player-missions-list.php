<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.player_missions');
$db = pw_db(); pw_admin_missions_require_ready($db);
$rows = $db->query(
    'SELECT pm.id, pm.world_key, pm.status, pm.started_at, pm.completes_at, pm.completed_at, pm.claimed_at,
            u.username, u.display_name, md.name AS mission_name,
            GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR "|~|") AS crew_names
     FROM game_player_missions pm
     JOIN users u ON u.id = pm.user_id
     JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
     LEFT JOIN game_player_mission_crew link ON link.player_mission_id = pm.id
     LEFT JOIN game_player_crew pc ON pc.id = link.player_crew_id
     LEFT JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
     GROUP BY pm.id
     ORDER BY CASE pm.status WHEN "active" THEN 0 WHEN "completed" THEN 1 ELSE 2 END, pm.started_at DESC, pm.id DESC
     LIMIT 200'
)->fetchAll();
$rows = array_map(static function ($row) {
    $row['id'] = (int)$row['id'];
    $row['crew_names'] = $row['crew_names'] !== null && $row['crew_names'] !== '' ? explode('|~|', $row['crew_names']) : [];
    return $row;
}, $rows);
pw_json(['ok' => true, 'missions' => $rows]);
