<?php
/**
 * Attach loot tables to one mission, with the chance each is opened on a
 * successful run.
 *
 * Deliberately its own endpoint rather than another field on the mission
 * definition save: this is gated by loot_tables.edit, so loot balancing can be
 * delegated to someone who cannot re-time a mission or change its rewards.
 */
require_once __DIR__ . '/loot-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('loot_tables.edit');
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a mission.');
$db = pw_db();
pw_missions_require_loot_tables_ready($db);

$missionStmt = $db->prepare('SELECT name FROM game_mission_definitions WHERE id = ?');
$missionStmt->execute([$missionId]);
$mission = $missionStmt->fetch();
if (!$mission) pw_error('Mission not found.', 404);

$links = pw_admin_loot_mission_links_input($input['tables'] ?? []);

try {
    $db->beginTransaction();
    pw_admin_loot_sync_mission_links($db, $missionId, $links);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_admin_activity(
    'mission_loot_tables_updated',
    'Set ' . count($links) . ' loot ' . (count($links) === 1 ? 'table' : 'tables') . ' on mission "' . $mission['name'] . '".',
    $admin
);
pw_json(['ok' => true]);
