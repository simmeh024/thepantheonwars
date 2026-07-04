<?php
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
$identifier = isset($input['identifier']) ? trim($input['identifier']) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';

if ($identifier === '' || $password === '') {
    pw_error('Enter your username/email and password.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, username, display_name, password_hash, failed_login_attempts, locked_until FROM users WHERE username = ? OR email = ?');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user) {
    // Same generic message as a bad password — don't reveal which part was wrong.
    pw_error('Incorrect username/email or password.', 401);
}

if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
    pw_error('Too many failed attempts. Try again in a few minutes.', 429);
}

if (!password_verify($password, $user['password_hash'])) {
    $attempts = (int)$user['failed_login_attempts'] + 1;
    $lockedUntil = null;
    if ($attempts >= 6) {
        $lockedUntil = date('Y-m-d H:i:s', time() + 5 * 60);
    }
    $stmt = $db->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
    $stmt->execute([$attempts, $lockedUntil, $user['id']]);
    pw_error('Incorrect username/email or password.', 401);
}

$stmt = $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
$stmt->execute([$user['id']]);

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];

pw_json(['ok' => true, 'user' => [
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'display_name' => $user['display_name'],
]]);
