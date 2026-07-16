<?php
/**
 * Starts a password-reset request without disclosing whether an address owns
 * an account. The response is deliberately identical for every email address
 * and every delivery outcome, preventing account enumeration.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);

$email = trim((string)($input['email'] ?? ''));
$genericResponse = [
    'ok' => true,
    'message' => 'If an account matches that address, a password-reset link has been sent. Check your inbox and spam folder.',
];

// Invalid input receives the same success-shaped answer as every other
// request. It must never be possible to learn whether an email exists.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pw_json($genericResponse);
}

$db = pw_db();
$ip = pw_client_ip();

try {
    // Throttle delivery by network and by account. These checks happen before
    // querying a matching user in the response path, and are intentionally
    // invisible to the browser just like all other outcomes.
    $ipLimit = $db->prepare(
        'SELECT COUNT(*) AS c FROM password_reset_tokens
         WHERE requested_ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $ipLimit->execute([$ip]);
    if ((int)$ipLimit->fetch()['c'] >= 5) {
        pw_json($genericResponse);
    }

    $userStmt = $db->prepare(
        'SELECT id, username, display_name, email, password_hash
         FROM users WHERE email = ? LIMIT 1'
    );
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    // OAuth-only accounts do not have a local password to reset. Keeping this
    // path silent also protects which sign-in method an address uses.
    if (!$user || $user['password_hash'] === null || $user['password_hash'] === '') {
        pw_json($genericResponse);
    }

    $userLimit = $db->prepare(
        'SELECT COUNT(*) AS c FROM password_reset_tokens
         WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
    );
    $userLimit->execute([(int)$user['id']]);
    if ((int)$userLimit->fetch()['c'] >= 3) {
        pw_json($genericResponse);
    }

    // One active link per account. A newer request invalidates an earlier
    // message, which keeps a stale inbox link from remaining usable.
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $db->beginTransaction();
    $invalidate = $db->prepare(
        'UPDATE password_reset_tokens
         SET used_at = UTC_TIMESTAMP()
         WHERE user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    $invalidate->execute([(int)$user['id']]);
    $insert = $db->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at)
         VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))'
    );
    $insert->execute([(int)$user['id'], $tokenHash, $ip]);
    $tokenId = (int)$db->lastInsertId();
    $db->commit();

    // The token stays in the URL fragment: browsers do not transmit fragments
    // to the page server, asset requests, analytics, or Referer headers.
    $resetUrl = 'https://thepantheonwars.com/password-reset.html#token=' . rawurlencode($token);
    $delivery = pw_send_template_email('password_reset', $user['email'], [
        'recipient_name' => $user['display_name'] ?: $user['username'],
        'recipient_email' => $user['email'],
        'reset_url' => $resetUrl,
    ]);
    if (!empty($delivery['sent'])) {
        pw_log_activity('password_reset_requested', 'Requested a secure password-reset link.', (int)$user['id'], $user['username']);
    } else {
        // Do not leave a usable credential around when the mail transport did
        // not accept it. The browser still receives the neutral response.
        $delete = $db->prepare('DELETE FROM password_reset_tokens WHERE id = ? AND token_hash = ?');
        $delete->execute([$tokenId, $tokenHash]);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // A missing migration or delivery problem must not turn into an account
    // oracle. The request can safely be retried later.
}

pw_json($genericResponse);
