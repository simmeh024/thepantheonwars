<?php
/**
 * Accepts a world id + the full ordered list of that world's layer ids and
 * rewrites sort_order 1..N. Scoped to world_id so a stale/mismatched id list
 * from a different world can't cross-contaminate ordering.
 */
require_once __DIR__ . '/../../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$worldId = isset($input['world_id']) ? (int)$input['world_id'] : 0;
$ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];
if ($worldId <= 0 || empty($ids)) {
    pw_error('Missing world id or layer order.');
}

$db = pw_db();
$existingIds = array_column(
    (function () use ($db, $worldId) {
        $stmt = $db->prepare('SELECT id FROM world_layers WHERE world_id = ?');
        $stmt->execute([$worldId]);
        return $stmt->fetchAll();
    })(),
    'id'
);
$existingIds = array_map('intval', $existingIds);

if (count(array_diff($existingIds, $ids)) > 0 || count(array_diff($ids, $existingIds)) > 0) {
    pw_error('Layer order is out of date. Reload and try again.', 409);
}

$db->beginTransaction();
$stmt = $db->prepare('UPDATE world_layers SET sort_order = ? WHERE id = ? AND world_id = ?');
foreach ($ids as $i => $id) {
    $stmt->execute([$i + 1, $id, $worldId]);
}
$db->commit();

pw_log_admin_activity('world_layer_updated', 'Reordered layers.', $adminUser);

pw_json(['ok' => true]);
