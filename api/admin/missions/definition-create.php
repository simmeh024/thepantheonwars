<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$db = pw_db(); pw_admin_mission_successions_require_ready($db);
$data = pw_admin_mission_definition_input($input);
$duplicate = $db->prepare('SELECT id FROM game_mission_definitions WHERE slug = ?'); $duplicate->execute([$data['slug']]);
if ($duplicate->fetch()) pw_error('A mission with that slug already exists.', 409);
pw_admin_validate_mission_succession($db, null, $data);
$stmt = $db->prepare(
    'INSERT INTO game_mission_definitions
     (world_key, name, slug, description, mission_type, duration_seconds, min_crew, max_crew, xp_reward, reputation_reward, is_enabled, sort_order, unlocks_after_mission_id, unlocks_after_completion_count)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$data['world_key'], $data['name'], $data['slug'], $data['description'], $data['mission_type'], $data['duration_seconds'], $data['min_crew'], $data['max_crew'], $data['xp_reward'], $data['reputation_reward'], $data['is_enabled'], $data['sort_order'], $data['unlocks_after_mission_id'], $data['unlocks_after_completion_count']]);
$id = (int)$db->lastInsertId();
pw_log_admin_activity('mission_definition_created', 'Created mission definition "' . $data['name'] . '".', $admin);
pw_json(['ok' => true, 'id' => $id]);
