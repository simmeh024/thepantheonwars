<?php
/**
 * Clears an active mute early. Mirrors the ban toggle's directness -- an
 * immediate state change, no queue -- rather than living under
 * api/admin/warnings/ since a mute can outlive the warning that created it
 * and isn't itself a member_warnings row.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_permission('warnings.manage');
$input = pw_input();
pw_require_csrf($input);

$targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
if ($targetUserId <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, display_name, muted_until FROM users WHERE id = ?');
$stmt->execute([$targetUserId]);
$target = $stmt->fetch();
if (!$target) {
    pw_error('Member not found.', 404);
}
if (empty($target['muted_until']) || strtotime($target['muted_until']) <= time()) {
    pw_error('This member is not currently muted.');
}

$update = $db->prepare('UPDATE users SET muted_until = NULL, mute_reason = NULL WHERE id = ?');
$update->execute([$targetUserId]);

pw_log_admin_activity('member_unmuted', 'Unmuted ' . $target['display_name'] . '.', $user);

pw_json(['ok' => true]);
