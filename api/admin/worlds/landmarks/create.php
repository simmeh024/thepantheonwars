<?php
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/landmarks-helpers.php';

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

$layerId = isset($input['layer_id']) && $input['layer_id'] !== null && $input['layer_id'] !== ''
    ? (int)$input['layer_id'] : null;
if ($layerId !== null) {
    $layerStmt = $db->prepare('SELECT id FROM world_layers WHERE id = ? AND world_id = ?');
    $layerStmt->execute([$layerId, $worldId]);
    if (!$layerStmt->fetch()) {
        pw_error('That layer does not belong to this world.', 400);
    }
}
$kind = $layerId !== null ? 'restricted' : 'distant';

$data = pw_validate_landmark_input($input);

$maxSort = $layerId !== null
    ? (function () use ($db, $layerId) {
        $s = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM world_landmarks WHERE layer_id = ?');
        $s->execute([$layerId]);
        return $s->fetch();
    })()
    : (function () use ($db, $worldId) {
        $s = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM world_landmarks WHERE world_id = ? AND layer_id IS NULL');
        $s->execute([$worldId]);
        return $s->fetch();
    })();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO world_landmarks (world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $worldId, $layerId, $sortOrder, $kind,
    $data['name'], $data['tag_label'], $data['description'], $data['quote_text'], $data['quote_cite'],
]);
$landmarkId = (int)$db->lastInsertId();

pw_log_admin_activity(
    'world_layer_updated',
    'Added ' . ($kind === 'distant' ? 'distant landmark' : 'landmark') . ' "' . $data['name'] . '" to world "' . $world['name'] . '".',
    $adminUser
);

pw_json(['ok' => true, 'id' => $landmarkId]);
