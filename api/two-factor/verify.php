<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../two-factor-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);
$userId = isset($_SESSION['pw_two_factor_pending_user_id']) ? (int)$_SESSION['pw_two_factor_pending_user_id'] : 0;
$issuedAt = isset($_SESSION['pw_two_factor_pending_at']) ? (int)$_SESSION['pw_two_factor_pending_at'] : 0;
$attempts = isset($_SESSION['pw_two_factor_pending_attempts']) ? (int)$_SESSION['pw_two_factor_pending_attempts'] : 0;
$identifier = isset($_SESSION['pw_two_factor_pending_identifier']) ? (string)$_SESSION['pw_two_factor_pending_identifier'] : 'two-factor';

if ($userId <= 0 || $issuedAt < time() - PW_TWO_FACTOR_PENDING_TTL) {
    pw_two_factor_clear_pending_login();
    pw_error('Your sign-in verification expired. Enter your password again.', 401);
}
if ($attempts >= 5) {
    pw_two_factor_clear_pending_login();
    pw_error('Too many authenticator attempts. Enter your password again.', 429);
}

$db = pw_db();
$ip = pw_client_ip();
try {
    $db->beginTransaction();
    $userStmt = $db->prepare('SELECT id, username, display_name, role, failed_login_attempts, locked_until, banned_at, banned_until FROM users WHERE id = ? FOR UPDATE');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    $row = $user ? pw_two_factor_get_row($db, $userId, true) : null;
    $secret = $row ? pw_two_factor_decrypt_secret($row['secret_ciphertext']) : null;
    $counter = $secret ? pw_two_factor_matching_counter($secret, (string)($input['code'] ?? '')) : null;
    $alreadyUsed = $counter !== null && $row['last_used_counter'] !== null && $counter <= (int)$row['last_used_counter'];

    if (!$user || pw_is_banned($user) || !empty($user['locked_until']) && strtotime($user['locked_until']) > time() || $counter === null || $alreadyUsed) {
        if ($user && !pw_is_banned($user)) {
            $failed = (int)$user['failed_login_attempts'] + 1;
            $lockedUntil = $failed >= 6 ? gmdate('Y-m-d H:i:s', time() + 300) : null;
            $db->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')->execute([$failed, $lockedUntil, $userId]);
        }
        $db->commit();
        $_SESSION['pw_two_factor_pending_attempts'] = $attempts + 1;
        pw_log_login_attempt($ip, $identifier, false);
        if ($user) pw_log_activity('two_factor_failed', 'Authenticator verification failed during password sign-in.', $userId, $user['username']);
        pw_error('That authenticator code is not valid. Please try again.', 401);
    }

    $db->prepare('UPDATE user_two_factor SET last_used_counter = ? WHERE user_id = ?')->execute([$counter, $userId]);
    $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')->execute([$ip, $userId]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_login_attempt($ip, $identifier, true);
session_regenerate_id(true);
pw_two_factor_clear_pending_login();
$_SESSION['user_id'] = $userId;
pw_issue_user_session($userId, 'password+totp');
pw_log_activity('two_factor_verified', 'Completed authenticator verification for password sign-in.', $userId, $user['username']);
pw_log_activity('login_ok', ucfirst($user['role']) . ' logged in with password and two-factor authentication.', $userId, $user['username']);
pw_log_activity('session_created', 'Created a password-and-two-factor-authenticated session.', $userId, $user['username']);

pw_json(['ok' => true, 'user' => ['id' => $userId, 'username' => $user['username'], 'display_name' => $user['display_name']]]);
