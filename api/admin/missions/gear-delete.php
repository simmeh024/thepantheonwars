<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.delete');
$input = pw_input(); pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing equipment.');
$db = pw_db(); pw_admin_mission_gear_require_ready($db);
$existing = $db->prepare('SELECT name FROM game_loot_definitions WHERE id = ?');
$existing->execute([$id]);
$item = $existing->fetch();
if (!$item) pw_error('Equipment not found.', 404);
/* Same rule as a crew template with ownership records: an item any player holds
 * is disabled, never deleted. The foreign keys would cascade the inventory and
 * equipped rows away with it, which silently takes something off a player's
 * record that they earned. */
$held = $db->prepare('SELECT 1 FROM game_player_loot WHERE loot_definition_id = ? AND quantity > 0 LIMIT 1');
$held->execute([$id]);
if ($held->fetch()) pw_error('Players already hold this item, so it cannot be deleted. Disable it instead to stop it dropping.', 409);
$db->prepare('DELETE FROM game_loot_definitions WHERE id = ?')->execute([$id]);
pw_log_admin_activity('mission_gear_deleted', 'Deleted unused equipment "' . $item['name'] . '".', $admin);
pw_json(['ok' => true]);
