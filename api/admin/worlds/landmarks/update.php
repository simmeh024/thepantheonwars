<?php
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/landmarks-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('worlds.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing landmark id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT wl.id, w.name AS world_name FROM world_landmarks wl
     JOIN worlds w ON w.id = wl.world_id WHERE wl.id = ?'
);
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Landmark not found.', 404);
}

// kind/layer_id/world_id are fixed at creation time -- not editable here.
$data = pw_validate_landmark_input($input);

$stmt = $db->prepare(
    'UPDATE world_landmarks SET name = ?, tag_label = ?, description = ?, quote_text = ?, quote_cite = ? WHERE id = ?'
);
$stmt->execute([$data['name'], $data['tag_label'], $data['description'], $data['quote_text'], $data['quote_cite'], $id]);

pw_log_admin_activity(
    'world_layer_updated',
    'Updated landmark "' . $data['name'] . '" in world "' . $existing['world_name'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
