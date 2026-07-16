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
$secret = isset($_SESSION['pw_two_factor_setup_secret']) ? (string)$_SESSION['pw_two_factor_setup_secret'] : '';
$createdAt = isset($_SESSION['pw_two_factor_setup_at']) ? (int)$_SESSION['pw_two_factor_setup_at'] : 0;

if (!pw_two_factor_table_available($db) || !pw_two_factor_crypto_available()) {
    pw_two_factor_clear_pending_setup();
    pw_error('Two-factor authentication is not available right now.', 503);
}
if ($secret === '' || $createdAt < time() - PW_TWO_FACTOR_PENDING_TTL) {
    pw_two_factor_clear_pending_setup();
    pw_error('Your setup request expired. Start setup again.', 410);
}

$counter = pw_two_factor_matching_counter($secret, (string)($input['code'] ?? ''));
if ($counter === null) {
    pw_error('That authenticator code is not valid. Check the time on your device and try again.', 422);
}
$ciphertext = pw_two_factor_encrypt_secret($secret);
if ($ciphertext === null) {
    pw_two_factor_clear_pending_setup();
    pw_error('Two-factor authentication could not be stored securely.', 500);
}

$stmt = $db->prepare(
    'INSERT INTO user_two_factor (user_id, secret_ciphertext, enabled_at, last_used_counter)
     VALUES (?, ?, UTC_TIMESTAMP(), ?)
     ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), enabled_at = UTC_TIMESTAMP(), last_used_counter = VALUES(last_used_counter)'
);
$stmt->execute([(int)$user['id'], $ciphertext, $counter]);
pw_two_factor_clear_pending_setup();
pw_log_activity('two_factor_enabled', 'Enabled authenticator-app two-factor authentication for password sign-ins.', (int)$user['id'], $user['username']);

pw_json(['ok' => true]);
