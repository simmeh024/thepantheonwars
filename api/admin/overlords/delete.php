<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('overlords.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing overlord id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT name FROM overlords WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Overlord not found.', 404);
}

// worlds.overlord_id -> NULL automatically via ON DELETE SET NULL, so the
// assigned world just reverts to "Unassigned" -- no manual cleanup needed.
$stmt = $db->prepare('DELETE FROM overlords WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity('overlord_deleted', 'Deleted overlord "' . $existing['name'] . '".', $adminUser);

pw_json(['ok' => true]);
