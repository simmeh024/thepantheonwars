<?php
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);

$user = pw_current_user();
if ($user) {
    // A regular logout revokes only this browser's registry record.
    try {
        pw_db()->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ? WHERE user_id = ? AND session_token_hash = ? AND revoked_at IS NULL')
            ->execute(['signed_out', (int)$user['id'], pw_session_hash(pw_current_session_token())]);
    } catch (Throwable $e) {}
    pw_log_activity('session_revoked', 'Signed out the current session.', (int)$user['id'], $user['username']);
}
pw_destroy_local_session();

pw_json(['ok' => true]);
