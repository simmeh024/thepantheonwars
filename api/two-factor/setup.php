<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../two-factor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$currentPassword = isset($input['current_password']) ? (string)$input['current_password'] : '';
$db = pw_db();

if (!pw_two_factor_table_available($db)) {
    pw_error('Two-factor authentication is being prepared. Please try again after the database update.', 503);
}
if (!pw_two_factor_crypto_available()) {
    pw_error('Two-factor authentication is not configured securely yet. Please contact an administrator.', 503);
}

$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$passwordHash = $stmt->fetchColumn();
if ($passwordHash === false || $passwordHash === null || $passwordHash === '') {
    pw_error('Two-factor authentication is available for password sign-ins only. Google sign-in already uses Google account protection.', 409);
}
if ($currentPassword === '' || !password_verify($currentPassword, $passwordHash)) {
    pw_error('Enter your current password to continue.', 403);
}

$secret = pw_two_factor_generate_secret();
$_SESSION['pw_two_factor_setup_secret'] = $secret;
$_SESSION['pw_two_factor_setup_at'] = time();

pw_json([
    'ok' => true,
    'secret' => $secret,
    'provisioning_uri' => pw_two_factor_provisioning_uri($user['email'], $secret),
    'expires_in' => PW_TWO_FACTOR_PENDING_TTL,
]);
