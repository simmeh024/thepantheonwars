<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing mission definition.');
$db = pw_db(); pw_admin_missions_require_ready($db);
$data = pw_admin_mission_definition_input($input);
$existing = $db->prepare('SELECT id FROM game_mission_definitions WHERE id = ?'); $existing->execute([$id]);
if (!$existing->fetch()) pw_error('Mission definition not found.', 404);
$duplicate = $db->prepare('SELECT id FROM game_mission_definitions WHERE slug = ? AND id != ?'); $duplicate->execute([$data['slug'], $id]);
if ($duplicate->fetch()) pw_error('A mission with that slug already exists.', 409);
$stmt = $db->prepare(
    'UPDATE game_mission_definitions SET world_key = ?, name = ?, slug = ?, description = ?, mission_type = ?, duration_seconds = ?,
     min_crew = ?, max_crew = ?, xp_reward = ?, reputation_reward = ?, is_enabled = ?, sort_order = ? WHERE id = ?'
);
$stmt->execute([$data['world_key'], $data['name'], $data['slug'], $data['description'], $data['mission_type'], $data['duration_seconds'], $data['min_crew'], $data['max_crew'], $data['xp_reward'], $data['reputation_reward'], $data['is_enabled'], $data['sort_order'], $id]);
pw_log_admin_activity('mission_definition_updated', 'Updated mission definition "' . $data['name'] . '".', $admin);
pw_json(['ok' => true]);
