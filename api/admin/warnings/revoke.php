<?php
/**
 * Revokes an active warning. The row is kept (status='revoked') so the
 * audit trail survives -- only warnings.delete permanently removes a row.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_permission('warnings.manage');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$revokeReason = isset($input['revoke_reason']) ? trim((string)$input['revoke_reason']) : '';

if ($id <= 0) {
    pw_error('Missing warning id.');
}
if ($revokeReason === '') {
    pw_error('Enter a reason for revoking this warning.');
}
if (mb_strlen($revokeReason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT w.id, w.status, w.user_id, u.display_name FROM member_warnings w JOIN users u ON u.id = w.user_id WHERE w.id = ?'
);
$stmt->execute([$id]);
$warning = $stmt->fetch();
if (!$warning) {
    pw_error('Warning not found.', 404);
}
if ($warning['status'] !== 'active') {
    pw_error('This warning has already been revoked.');
}

$update = $db->prepare(
    "UPDATE member_warnings SET status = 'revoked', revoked_by_user_id = ?, revoked_by_username = ?, revoke_reason = ?, revoked_at = NOW() WHERE id = ?"
);
$update->execute([$user['id'], $user['username'], $revokeReason, $id]);

pw_log_admin_activity(
    'warning_revoked',
    'Revoked a warning on ' . $warning['display_name'] . ': ' . $revokeReason,
    $user
);

pw_json(['ok' => true]);
