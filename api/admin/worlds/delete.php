<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing world id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT name FROM worlds WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('World not found.', 404);
}

// Cascades to world_layers, world_layer_sublocations, and world_landmarks
// via their FK ON DELETE CASCADE constraints -- no manual child-row cleanup
// needed.
$stmt = $db->prepare('DELETE FROM worlds WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity('world_deleted', 'Deleted world "' . $existing['name'] . '" and all of its lore content.', $adminUser);

pw_json(['ok' => true]);
