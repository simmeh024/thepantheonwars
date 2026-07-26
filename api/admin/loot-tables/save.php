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

    if ($id === null) {
        $stmt = $db->prepare(
            $researchLocksReady
                ? 'INSERT INTO game_loot_tables (name, slug, description, is_enabled, is_research_rare) VALUES (?, ?, ?, ?, ?)'
                : 'INSERT INTO game_loot_tables (name, slug, description, is_enabled) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute($researchLocksReady
            ? [$data['name'], $data['slug'], $data['description'], $data['is_enabled'], $data['is_research_rare']]
            : [$data['name'], $data['slug'], $data['description'], $data['is_enabled']]
        );
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
        $stmt = $db->prepare(
            $researchLocksReady
                ? 'UPDATE game_loot_tables SET name = ?, slug = ?, description = ?, is_enabled = ?, is_research_rare = ? WHERE id = ?'
                : 'UPDATE game_loot_tables SET name = ?, slug = ?, description = ?, is_enabled = ? WHERE id = ?'
        );
        $stmt->execute($researchLocksReady
            ? [$data['name'], $data['slug'], $data['description'], $data['is_enabled'], $data['is_research_rare'], $id]
            : [$data['name'], $data['slug'], $data['description'], $data['is_enabled'], $id]
        );
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
