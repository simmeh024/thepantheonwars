<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db();
pw_admin_missions_require_ready($db);
$rows = $db->query(
    'SELECT id, world_key, name, slug, description, mission_type, duration_seconds, min_crew, max_crew,
            xp_reward, reputation_reward, is_enabled, sort_order, created_at, updated_at
     FROM game_mission_definitions ORDER BY world_key ASC, sort_order ASC, id ASC'
)->fetchAll();
$rows = array_map(static function ($row) {
    foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order'] as $field) $row[$field] = (int)$row[$field];
    $row['is_enabled'] = (bool)$row['is_enabled'];
    return $row;
}, $rows);
pw_json(['ok' => true, 'missions' => $rows]);
