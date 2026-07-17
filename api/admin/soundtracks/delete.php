<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('soundtracks.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing soundtrack id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT heading FROM soundtracks WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Soundtrack not found.', 404);
}

$stmt = $db->prepare('DELETE FROM soundtracks WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity('soundtrack_deleted', 'Deleted soundtrack "' . $existing['heading'] . '".', $adminUser);

pw_json(['ok' => true]);
