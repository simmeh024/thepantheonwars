<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$password = isset($input['current_password']) ? (string)$input['current_password'] : '';

$stmt = pw_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row) {
    pw_error('Your account could not be verified.', 403);
}
$hasPassword = $row['password_hash'] !== null && $row['password_hash'] !== '';
if ($hasPassword && ($password === '' || !password_verify($password, $row['password_hash']))) {
    pw_error('Your current password is incorrect.', 403);
}

$revoked = pw_revoke_user_sessions((int)$user['id'], null, 'user_revoked_all');
pw_log_activity('sessions_revoked_all', 'Signed out all active sessions, including the current session.', (int)$user['id'], $user['username']);
pw_destroy_local_session();
pw_json(['ok' => true, 'revoked' => $revoked, 'signed_out_current' => true]);
