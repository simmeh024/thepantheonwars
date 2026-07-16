<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../two-factor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('members.reset_two_factor');
$input = pw_input();
pw_require_csrf($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) pw_error('Missing member id.');

$db = pw_db();
if (!pw_two_factor_table_available($db)) {
    pw_error('Two-factor authentication is not available on this deployment yet.', 503);
}
$stmt = $db->prepare('SELECT id, username FROM users WHERE id = ?');
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) pw_error('Member not found.', 404);

$delete = $db->prepare('DELETE FROM user_two_factor WHERE user_id = ?');
$delete->execute([$id]);
if ($delete->rowCount() === 0) {
    pw_json(['ok' => true, 'reset' => false]);
}

$revoked = pw_revoke_user_sessions($id, null, 'two_factor_reset_by_admin');
pw_log_admin_activity('member_two_factor_reset', 'Reset two-factor authentication for ' . $member['username'] . ' and revoked ' . $revoked . ' active session(s).', $adminUser);
if ((int)$adminUser['id'] === $id) {
    pw_destroy_local_session();
}

pw_json(['ok' => true, 'reset' => true, 'sessions_revoked' => $revoked]);
