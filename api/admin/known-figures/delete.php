<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('known_figures.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing known figure id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT name FROM known_figures WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Known figure not found.', 404);
}

$stmt = $db->prepare('DELETE FROM known_figures WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity('known_figure_deleted', 'Deleted known figure "' . $existing['name'] . '".', $adminUser);

pw_json(['ok' => true]);
