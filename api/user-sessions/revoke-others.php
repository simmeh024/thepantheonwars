<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$revoked = pw_revoke_user_sessions((int)$user['id'], pw_current_session_token(), 'user_revoked_others');
pw_log_activity('sessions_revoked_others', 'Signed out ' . $revoked . ' other active session(s).', (int)$user['id'], $user['username']);
pw_json(['ok' => true, 'revoked' => $revoked]);
