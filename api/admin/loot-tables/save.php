<?php
require_once __DIR__ . '/loot-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('loot_tables.edit');
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_missions_require_loot_table_gear_ready($db);

$rawId = $input['id'] ?? null;
$id = $rawId === null || $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT);
if ($id !== null && ($id === false || $id < 1)) pw_error('Missing loot table.');

$data = pw_admin_loot_table_input($input);
$entries = pw_admin_loot_entries_input($input['entries'] ?? []);
pw_admin_loot_require_sources_exist($db, $entries);
$researchLocksReady = pw_mission_loot_table_research_locks_ready($db);

try {
    $db->beginTransaction();
    $duplicate = $db->prepare('SELECT id FROM game_loot_tables WHERE slug = ?' . ($id !== null ? ' AND id != ?' : ''));
    $duplicate->execute($id !== null ? [$data['slug'], $id] : [$data['slug']]);
    if ($duplicate->fetch()) { $db->rollBack(); pw_error('A loot table with that slug already exists.', 409); }

    /* Two optional migrations now decide which columns exist here, so the
     * column list is built from one array rather than as a branch per flag --
     * that is exactly how a placeholder/value count silently desyncs, and PDO
     * only reports it at execute() against the live database. */
    $sweepFlagReady = pw_mission_loot_table_sweep_flag_ready($db);
    $columns = ['name', 'slug', 'description', 'is_enabled'];
    $values = [$data['name'], $data['slug'], $data['description'], $data['is_enabled']];
    if ($researchLocksReady) { $columns[] = 'is_research_rare'; $values[] = $data['is_research_rare']; }
    if ($sweepFlagReady) { $columns[] = 'is_sweep_only'; $values[] = $data['is_sweep_only']; }

    if ($id === null) {
        $stmt = $db->prepare('INSERT INTO game_loot_tables (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute($values);
        $id = (int)$db->lastInsertId();
        $action = 'loot_table_created';
    } else {
        $existing = $db->prepare($researchLocksReady
            ? 'SELECT id, requires_research_unlock FROM game_loot_tables WHERE id = ?'
            : 'SELECT id FROM game_loot_tables WHERE id = ?');
        $existing->execute([$id]);
        $existingRow = $existing->fetch();
        if (!$existingRow) { $db->rollBack(); pw_error('Loot table not found.', 404); }
        if ($researchLocksReady && !empty($existingRow['requires_research_unlock']) && !$data['is_research_rare']) {
            throw new RuntimeException('A research-locked loot table must remain marked as a rare research table. Retire its linked protocol first.');
        }
        $sets = array_map(static function ($column) { return $column . ' = ?'; }, $columns);
        $stmt = $db->prepare('UPDATE game_loot_tables SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute(array_merge($values, [$id]));
        $action = 'loot_table_updated';
    }

    pw_admin_loot_sync_entries($db, $id, $entries);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_admin_activity(
    $action,
    ($action === 'loot_table_created' ? 'Created' : 'Updated') . ' loot table "' . $data['name'] . '" (' . count($entries) . ' ' . (count($entries) === 1 ? 'entry' : 'entries') . ').',
    $admin
);
pw_json(['ok' => true, 'id' => $id]);
