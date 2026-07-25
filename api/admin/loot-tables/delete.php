<?php
require_once __DIR__ . '/loot-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('loot_tables.edit');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Missing loot table.');
$db = pw_db();
pw_missions_require_loot_tables_ready($db);

$stmt = $db->prepare('SELECT name FROM game_loot_tables WHERE id = ?');
$stmt->execute([$id]);
$table = $stmt->fetch();
if (!$table) pw_error('Loot table not found.', 404);

/* Entries and mission attachments both cascade, so deleting a table quietly
 * detaches it from every mission that opened it. That is a bigger change than
 * the button suggests, so an attached table has to be detached first rather
 * than silently altering those missions' rewards. */
$linked = $db->prepare(
    'SELECT md.name FROM game_mission_loot_tables link
     JOIN game_mission_definitions md ON md.id = link.mission_definition_id
     WHERE link.loot_table_id = ? ORDER BY md.name ASC'
);
$linked->execute([$id]);
$missions = array_column($linked->fetchAll(), 'name');
if ($missions) {
    pw_error('This loot table is still attached to ' . implode(', ', array_slice($missions, 0, 5))
        . (count($missions) > 5 ? ' and ' . (count($missions) - 5) . ' more' : '')
        . '. Detach it from those missions before deleting it.', 409);
}

$db->prepare('DELETE FROM game_loot_tables WHERE id = ?')->execute([$id]);
pw_log_admin_activity('loot_table_deleted', 'Deleted loot table "' . $table['name'] . '".', $admin);
pw_json(['ok' => true]);
