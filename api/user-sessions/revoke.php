<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
if ($sessionId <= 0) pw_error('Missing session.');

try {
    $stmt = pw_db()->prepare('SELECT id, session_token_hash FROM user_sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([$sessionId, $user['id']]);
    $target = $stmt->fetch();
    if (!$target) pw_error('That session is no longer active.', 404);

    $isCurrent = hash_equals(pw_session_hash((string)pw_current_session_token()), $target['session_token_hash']);
    pw_db()->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ? WHERE id = ? AND user_id = ?')
        ->execute(['user_revoked', $sessionId, $user['id']]);
    pw_log_activity('session_revoked', 'Revoked ' . ($isCurrent ? 'the current session.' : 'another active session.'), (int)$user['id'], $user['username']);
    if ($isCurrent) pw_destroy_local_session();
    pw_json(['ok' => true, 'signed_out_current' => $isCurrent]);
} catch (Throwable $e) {
    pw_error('Could not revoke that session. Please try again.', 503);
}
