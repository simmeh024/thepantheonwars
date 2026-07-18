<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('reputation.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing level id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT name FROM reputation_levels WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Reputation level not found.', 404);
}

$stmt = $db->prepare('DELETE FROM reputation_levels WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity('reputation_level_deleted', 'Deleted reputation level "' . $existing['name'] . '".', $adminUser);

pw_json(['ok' => true]);
