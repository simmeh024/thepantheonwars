<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.delete');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing crew character.');
$db = pw_db(); pw_admin_missions_require_ready($db);
$existing = $db->prepare('SELECT name FROM game_crew_definitions WHERE id = ?'); $existing->execute([$id]); $crew = $existing->fetch();
if (!$crew) pw_error('Crew character not found.', 404);
$used = $db->prepare('SELECT 1 FROM game_player_crew WHERE crew_definition_id = ? LIMIT 1'); $used->execute([$id]);
if ($used->fetch()) pw_error('This crew template has player ownership records and cannot be deleted. Disable it instead.', 409);
$db->prepare('DELETE FROM game_crew_definitions WHERE id = ?')->execute([$id]);
pw_log_admin_activity('mission_crew_deleted', 'Deleted unused crew template "' . $crew['name'] . '".', $admin);
pw_json(['ok' => true]);
