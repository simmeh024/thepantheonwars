<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('members.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

if ($id === (int)$adminUser['id']) {
    pw_error('You can\'t delete your own account.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Member not found.', 404);
}

// Capture the username before the row (and everything cascading from it) is
// gone, so the audit trail still reads naturally afterward.
$targetLabel = $existing['username'];

$stmt = $db->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

$avatarPath = __DIR__ . '/../../../uploads/avatars/' . $id . '.jpg';
if (file_exists($avatarPath)) {
    @unlink($avatarPath);
}

pw_log_admin_activity(
    'member_deleted',
    'Permanently deleted the account ' . $targetLabel . ' (and their comments, forum posts, and reactions).',
    $adminUser
);

pw_json(['ok' => true]);
