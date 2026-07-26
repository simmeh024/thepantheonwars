<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing crew character.');
$db = pw_db(); pw_admin_missions_require_ready($db);
$data = pw_admin_mission_crew_input($input);
$capacityReady = pw_mission_crew_capacity_ready($db);
$existing = $db->prepare('SELECT id FROM game_crew_definitions WHERE id = ?'); $existing->execute([$id]);
if (!$existing->fetch()) pw_error('Crew character not found.', 404);
$duplicate = $db->prepare('SELECT id FROM game_crew_definitions WHERE slug = ? AND id != ?'); $duplicate->execute([$data['slug'], $id]);
if ($duplicate->fetch()) pw_error('A crew character with that slug already exists.', 409);
$stmt = $db->prepare(
    $capacityReady
        ? 'UPDATE game_crew_definitions SET name = ?, slug = ?, description = ?, role = ?, portrait_url = ?, starting_level = ?, world_affinity = ?, tier = ?, is_starter = ?, is_enabled = ? WHERE id = ?'
        : 'UPDATE game_crew_definitions SET name = ?, slug = ?, description = ?, role = ?, portrait_url = ?, starting_level = ?, world_affinity = ?, is_starter = ?, is_enabled = ? WHERE id = ?'
);
$stmt->execute($capacityReady
    ? [$data['name'], $data['slug'], $data['description'], $data['role'], $data['portrait_url'], $data['starting_level'], $data['world_affinity'], $data['tier'], $data['is_starter'], $data['is_enabled'], $id]
    : [$data['name'], $data['slug'], $data['description'], $data['role'], $data['portrait_url'], $data['starting_level'], $data['world_affinity'], $data['is_starter'], $data['is_enabled'], $id]
);
pw_log_admin_activity('mission_crew_updated', 'Updated crew template "' . $data['name'] . '".', $admin);
pw_json(['ok' => true]);
