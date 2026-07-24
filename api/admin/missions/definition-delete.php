<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.delete');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing mission definition.');
$db = pw_db(); pw_admin_missions_require_ready($db);
$existing = $db->prepare('SELECT name FROM game_mission_definitions WHERE id = ?'); $existing->execute([$id]); $mission = $existing->fetch();
if (!$mission) pw_error('Mission definition not found.', 404);
$used = $db->prepare('SELECT 1 FROM game_player_missions WHERE mission_definition_id = ? LIMIT 1'); $used->execute([$id]);
if ($used->fetch()) pw_error('This mission has player history and cannot be deleted. Disable it instead.', 409);
$db->prepare('DELETE FROM game_mission_definitions WHERE id = ?')->execute([$id]);
pw_log_admin_activity('mission_definition_deleted', 'Deleted unused mission definition "' . $mission['name'] . '".', $admin);
pw_json(['ok' => true]);
