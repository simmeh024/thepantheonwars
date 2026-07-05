<?php
/**
 * Self-service password change. Requires the user's CURRENT password (not
 * just an active session) before allowing a new one — standard defense
 * against a hijacked/left-open session being used to lock the real owner
 * out of their account.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$currentPassword = isset($input['current_password']) ? (string)$input['current_password'] : '';
$newPassword = isset($input['new_password']) ? (string)$input['new_password'] : '';

if ($currentPassword === '' || $newPassword === '') {
    pw_error('Enter your current password and a new password.');
}
if (strlen($newPassword) < 8) {
    pw_error('New password needs to be at least 8 characters.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    pw_error('Your current password is incorrect.', 403);
}

if (password_verify($newPassword, $row['password_hash'])) {
    pw_error('That is already your current password. Choose a different one.');
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([$newHash, $user['id']]);

pw_json(['ok' => true]);
