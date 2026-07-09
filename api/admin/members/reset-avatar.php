<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('members.reset_avatar');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, username FROM users WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Member not found.', 404);
}

$avatarPath = __DIR__ . '/../../../uploads/avatars/' . $id . '.jpg';
$removed = false;
if (file_exists($avatarPath)) {
    $removed = @unlink($avatarPath);
    if (!$removed) {
        pw_error('Could not remove the avatar file. Please try again.', 500);
    }
}

if ($removed) {
    pw_log_admin_activity(
        'member_avatar_reset',
        'Reset the avatar for ' . $existing['username'] . '.',
        $adminUser
    );
}

pw_json(['ok' => true, 'removed' => $removed]);
