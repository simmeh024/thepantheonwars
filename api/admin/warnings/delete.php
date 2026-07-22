<?php
/** Permanently deletes a warning row -- gated separately from revoke. */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_permission('warnings.delete');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing warning id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT w.id, w.reason, u.display_name FROM member_warnings w JOIN users u ON u.id = w.user_id WHERE w.id = ?'
);
$stmt->execute([$id]);
$warning = $stmt->fetch();
if (!$warning) {
    pw_error('Warning not found.', 404);
}

$delete = $db->prepare('DELETE FROM member_warnings WHERE id = ?');
$delete->execute([$id]);

pw_log_admin_activity(
    'warning_deleted',
    'Deleted a warning on ' . $warning['display_name'] . ': ' . $warning['reason'],
    $user
);

pw_json(['ok' => true]);
