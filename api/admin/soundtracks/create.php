<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/soundtracks-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('soundtracks.edit');

$input = pw_input();
pw_require_csrf($input);

$data = pw_validate_soundtrack_input($input);

$db = pw_db();

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM soundtracks')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO soundtracks (
        eyebrow, heading, description, spotify_url,
        spotify_embed_type, spotify_embed_id, is_published, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $data['eyebrow'], $data['heading'], $data['description'], $data['spotify_url'],
    $data['spotify_embed_type'], $data['spotify_embed_id'], $data['is_published'], $sortOrder,
]);
$soundtrackId = (int)$db->lastInsertId();

pw_log_admin_activity('soundtrack_created', 'Added soundtrack "' . $data['heading'] . '".', $adminUser);

pw_json(['ok' => true, 'id' => $soundtrackId]);
