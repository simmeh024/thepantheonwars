<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$db = pw_db(); pw_admin_missions_require_ready($db);
$data = pw_admin_mission_crew_input($input);
$duplicate = $db->prepare('SELECT id FROM game_crew_definitions WHERE slug = ?'); $duplicate->execute([$data['slug']]);
if ($duplicate->fetch()) pw_error('A crew character with that slug already exists.', 409);
$stmt = $db->prepare(
    'INSERT INTO game_crew_definitions
     (name, slug, description, role, portrait_url, starting_level, world_affinity, is_starter, is_enabled)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$data['name'], $data['slug'], $data['description'], $data['role'], $data['portrait_url'], $data['starting_level'], $data['world_affinity'], $data['is_starter'], $data['is_enabled']]);
$id = (int)$db->lastInsertId();
pw_log_admin_activity('mission_crew_created', 'Created crew template "' . $data['name'] . '".', $admin);
pw_json(['ok' => true, 'id' => $id]);
