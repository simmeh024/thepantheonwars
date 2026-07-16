<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../two-factor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
$currentPassword = isset($input['current_password']) ? (string)$input['current_password'] : '';
$code = (string)($input['code'] ?? '');

if (!pw_two_factor_table_available($db)) {
    pw_error('Two-factor authentication is not available right now.', 503);
}
$passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$passwordStmt->execute([$user['id']]);
$passwordHash = $passwordStmt->fetchColumn();
if ($passwordHash === false || $passwordHash === null || $currentPassword === '' || !password_verify($currentPassword, $passwordHash)) {
    pw_error('Enter your current password to continue.', 403);
}

$row = pw_two_factor_get_row($db, (int)$user['id']);
$secret = $row ? pw_two_factor_decrypt_secret($row['secret_ciphertext']) : null;
if ($secret === null || pw_two_factor_matching_counter($secret, $code) === null) {
    pw_error('Enter a valid authenticator code to turn off two-factor authentication.', 422);
}

$db->prepare('DELETE FROM user_two_factor WHERE user_id = ?')->execute([(int)$user['id']]);
pw_two_factor_clear_pending_setup();
pw_log_activity('two_factor_disabled', 'Disabled authenticator-app two-factor authentication.', (int)$user['id'], $user['username']);

pw_json(['ok' => true]);
