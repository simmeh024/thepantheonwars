<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/soundtracks-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('soundtracks.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing soundtrack id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM soundtracks WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    pw_error('Soundtrack not found.', 404);
}

$data = pw_validate_soundtrack_input($input);

$stmt = $db->prepare(
    'UPDATE soundtracks SET
        eyebrow = ?, heading = ?, description = ?, spotify_url = ?,
        spotify_embed_type = ?, spotify_embed_id = ?, is_published = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['eyebrow'], $data['heading'], $data['description'], $data['spotify_url'],
    $data['spotify_embed_type'], $data['spotify_embed_id'], $data['is_published'],
    $id,
]);

pw_log_admin_activity('soundtrack_updated', 'Updated soundtrack "' . $data['heading'] . '".', $adminUser);

pw_json(['ok' => true]);
