<?php
/**
 * Generates a brand-new random password for a member and stores its hash,
 * immediately invalidating whatever password they had before. The plaintext
 * password is returned once in the JSON response so the admin console can
 * show it to the admin a single time -- it is never stored or logged in
 * plaintext anywhere (the activity log only records that a reset happened).
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_admin();

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, username FROM users WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Member not found.', 404);
}

$password = pw_generate_password(14);
$hash = password_hash($password, PASSWORD_DEFAULT);

$update = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$update->execute([$hash, $id]);

pw_log_admin_activity(
    'member_password_reset',
    'Generated a new password for ' . $existing['username'] . '.',
    $adminUser
);

pw_json(['ok' => true, 'password' => $password]);
