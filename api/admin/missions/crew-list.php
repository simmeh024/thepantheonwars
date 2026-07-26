<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db(); pw_admin_missions_require_ready($db);
$capacityReady = pw_mission_crew_capacity_ready($db);
$rows = $db->query(
    'SELECT c.id, c.name, c.slug, c.description, c.role, c.portrait_url, c.starting_level, c.world_affinity, '
    . ($capacityReady ? 'c.tier,' : '"common" AS tier,') . '
            c.is_starter, c.is_enabled, c.created_at, c.updated_at, COUNT(pc.id) AS player_count
     FROM game_crew_definitions c
     LEFT JOIN game_player_crew pc ON pc.crew_definition_id = c.id
     GROUP BY c.id
     ORDER BY c.is_starter DESC, c.role ASC, c.name ASC'
)->fetchAll();
$rows = array_map(static function ($row) {
    $row['id'] = (int)$row['id']; $row['starting_level'] = (int)$row['starting_level']; $row['player_count'] = (int)$row['player_count'];
    $row['is_starter'] = (bool)$row['is_starter']; $row['is_enabled'] = (bool)$row['is_enabled'];
    return $row;
}, $rows);
pw_json(['ok' => true, 'crew' => $rows, 'crew_capacity_ready' => $capacityReady]);
