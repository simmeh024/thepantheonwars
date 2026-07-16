<?php
/**
 * Consumes a short-lived, single-use password-reset token. Only a SHA-256
 * hash is stored in MariaDB; the raw token exists solely in the email link
 * and the browser's in-memory form submission.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);

$token = strtolower(trim((string)($input['token'] ?? '')));
$password = (string)($input['password'] ?? '');
$confirmation = (string)($input['password_confirmation'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    pw_error('That reset link is invalid or has expired. Request a new one.', 400);
}
if (strlen($password) < 12) {
    pw_error('Use a password with at least 12 characters.');
}
if ($password !== $confirmation) {
    pw_error('The passwords do not match.');
}
if (pw_password_is_pwned($password)) {
    pw_error('That password has appeared in known data breaches. Please choose a different one.');
}

$db = pw_db();
$tokenHash = hash('sha256', $token);

try {
    $db->beginTransaction();
    $lookup = $db->prepare(
        'SELECT pr.id, pr.user_id, u.username
         FROM password_reset_tokens pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?
           AND pr.used_at IS NULL
           AND pr.expires_at > UTC_TIMESTAMP()
         LIMIT 1
         FOR UPDATE'
    );
    $lookup->execute([$tokenHash]);
    $record = $lookup->fetch();
    if (!$record) {
        $db->rollBack();
        pw_error('That reset link is invalid or has expired. Request a new one.', 400);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $updateUser = $db->prepare(
        'UPDATE users
         SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL
         WHERE id = ?'
    );
    $updateUser->execute([$passwordHash, (int)$record['user_id']]);
    $consume = $db->prepare(
        'UPDATE password_reset_tokens
         SET used_at = UTC_TIMESTAMP()
         WHERE id = ? AND used_at IS NULL'
    );
    $consume->execute([(int)$record['id']]);
    if ($consume->rowCount() !== 1) {
        $db->rollBack();
        pw_error('That reset link is invalid or has expired. Request a new one.', 400);
    }
    $db->commit();

    // A credential change invalidates every remembered device. The person can
    // deliberately sign in again with their new password.
    pw_revoke_user_sessions((int)$record['user_id'], null, 'password_reset');
    try {
        pw_log_activity('password_reset_completed', 'Reset the account password through a secure one-time link.', (int)$record['user_id'], $record['username']);
    } catch (Throwable $e) {
        // The password is already committed. An audit-log outage must never
        // report a failed reset or encourage the user to reuse this token.
    }
    pw_json(['ok' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    pw_error('That reset link is invalid or has expired. Request a new one.', 400);
}
