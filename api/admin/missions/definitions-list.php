<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db();
pw_admin_mission_successions_require_ready($db);
$rows = $db->query(
    'SELECT mission.id, mission.world_key, mission.name, mission.slug, mission.description, mission.mission_type,
            mission.duration_seconds, mission.min_crew, mission.max_crew, mission.xp_reward, mission.reputation_reward,
            mission.is_enabled, mission.sort_order, mission.unlocks_after_mission_id, mission.unlocks_after_completion_count,
            prerequisite.name AS unlocks_after_mission_name, mission.created_at, mission.updated_at
     FROM game_mission_definitions mission
     LEFT JOIN game_mission_definitions prerequisite ON prerequisite.id = mission.unlocks_after_mission_id
     ORDER BY mission.world_key ASC, mission.sort_order ASC, mission.id ASC'
)->fetchAll();
$rows = array_map(static function ($row) {
    foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order', 'unlocks_after_completion_count'] as $field) $row[$field] = (int)$row[$field];
    $row['unlocks_after_mission_id'] = $row['unlocks_after_mission_id'] !== null ? (int)$row['unlocks_after_mission_id'] : null;
    $row['is_enabled'] = (bool)$row['is_enabled'];
    return $row;
}, $rows);
pw_json(['ok' => true, 'missions' => $rows]);
