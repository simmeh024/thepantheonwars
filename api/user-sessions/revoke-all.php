<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$password = isset($input['current_password']) ? (string)$input['current_password'] : '';
if ($password === '') pw_error('Enter your current password to sign out everywhere.', 403);

$stmt = pw_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($password, $row['password_hash'])) {
    pw_error('Your current password is incorrect.', 403);
}

$revoked = pw_revoke_user_sessions((int)$user['id'], null, 'user_revoked_all');
pw_log_activity('sessions_revoked_all', 'Signed out all active sessions, including the current session.', (int)$user['id'], $user['username']);
pw_destroy_local_session();
pw_json(['ok' => true, 'revoked' => $revoked, 'signed_out_current' => true]);
