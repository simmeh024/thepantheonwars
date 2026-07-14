<?php
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);

$identifier = isset($input['identifier']) ? trim($input['identifier']) : '';
$password = isset($input['password']) ? (string)$input['password'] : '';

if ($identifier === '' || $password === '') {
    pw_error('Enter your username/email and password.');
}

$db = pw_db();
$ip = pw_client_ip();

// IP-based throttle, independent of the per-account lockout below -- catches
// credential stuffing spread across many different usernames from one
// source, which a per-account counter alone can't see.
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip_address = ? AND success = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
$stmt->execute([$ip]);
if ((int)$stmt->fetch()['c'] >= 20) {
    pw_log_activity('login_ip_blocked', 'Blocked "' . $identifier . '" after too many failed attempts from this network.', null, $identifier);
    pw_error('Too many login attempts from this network. Try again in a few minutes.', 429);
}

$stmt = $db->prepare('SELECT id, username, display_name, password_hash, failed_login_attempts, locked_until, role, banned_at, banned_until FROM users WHERE username = ? OR email = ?');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user) {
    // Same generic message as a bad password — don't reveal which part was wrong.
    pw_log_login_attempt($ip, $identifier, false);
    pw_log_activity('login_failed', 'Failed login for "' . $identifier . '" (no such account).', null, $identifier);
    pw_error('Incorrect username/email or password.', 401);
}

if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
    pw_log_login_attempt($ip, $identifier, false);
    pw_log_activity('login_locked', 'Login attempt on a locked account.', (int)$user['id'], $user['username']);
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
    pw_log_login_attempt($ip, $identifier, false);
    pw_log_activity(
        'login_failed',
        'Incorrect password' . ($lockedUntil ? ' — account now locked for 5 minutes.' : '.'),
        (int)$user['id'],
        $user['username']
    );
    pw_error('Incorrect username/email or password.', 401);
}

if (pw_is_banned($user)) {
    // Only reveal the suspension after the password has checked out, so a
    // banned account can't be fingerprinted by a guess against the identifier alone.
    pw_log_login_attempt($ip, $identifier, false);
    pw_log_activity('login_banned', 'Login blocked: account is suspended.', (int)$user['id'], $user['username']);
    pw_error('This account has been suspended.', 403);
}

// Opportunistically upgrade the hash if PHP's default algorithm/cost has
// changed since this hash was created (e.g. a bcrypt cost bump), so existing
// users benefit without a bulk migration.
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
}

$stmt = $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?');
$stmt->execute([$ip, $user['id']]);
pw_log_login_attempt($ip, $identifier, true);

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
pw_issue_user_session((int)$user['id'], 'password');

pw_log_activity('login_ok', ucfirst($user['role']) . ' logged in.', (int)$user['id'], $user['username']);
pw_log_activity('session_created', 'Created a password-authenticated session.', (int)$user['id'], $user['username']);

pw_json(['ok' => true, 'user' => [
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'display_name' => $user['display_name'],
]]);
