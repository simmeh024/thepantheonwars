<?php
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/layers-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$worldId = isset($input['world_id']) ? (int)$input['world_id'] : 0;
if ($worldId <= 0) {
    pw_error('Missing world id.');
}

$db = pw_db();
$worldStmt = $db->prepare('SELECT id, name FROM worlds WHERE id = ?');
$worldStmt->execute([$worldId]);
$world = $worldStmt->fetch();
if (!$world) {
    pw_error('World not found.', 404);
}

$data = pw_validate_layer_input($input);
$sublocations = pw_parse_sublocations_textarea(isset($input['sublocations_text']) ? $input['sublocations_text'] : '');

$maxSort = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM world_layers WHERE world_id = ?');
$maxSort->execute([$worldId]);
$sortOrder = (int)$maxSort->fetch()['m'] + 1;

$db->beginTransaction();

$stmt = $db->prepare(
    'INSERT INTO world_layers (world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $worldId, $sortOrder, $data['name'], $data['theme_tags'], $data['tagline'],
    $data['description'], $data['quote_text'], $data['quote_cite'], $data['tint_key'],
]);
$layerId = (int)$db->lastInsertId();

$subStmt = $db->prepare('INSERT INTO world_layer_sublocations (layer_id, sort_order, label) VALUES (?, ?, ?)');
foreach ($sublocations as $i => $label) {
    $subStmt->execute([$layerId, $i + 1, $label]);
}

$db->commit();

pw_log_admin_activity(
    'world_layer_updated',
    'Added layer "' . $data['name'] . '" to world "' . $world['name'] . '".',
    $adminUser
);

pw_json(['ok' => true, 'id' => $layerId]);
