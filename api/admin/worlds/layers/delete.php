<?php
require_once __DIR__ . '/../../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing layer id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT wl.id, wl.name, w.name AS world_name FROM world_layers wl
     JOIN worlds w ON w.id = wl.world_id WHERE wl.id = ?'
);
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Layer not found.', 404);
}

// Cascades to world_layer_sublocations and any nested (restricted)
// world_landmarks via their FK ON DELETE CASCADE constraints.
$db->prepare('DELETE FROM world_layers WHERE id = ?')->execute([$id]);

pw_log_admin_activity(
    'world_layer_updated',
    'Deleted layer "' . $existing['name'] . '" from world "' . $existing['world_name'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
