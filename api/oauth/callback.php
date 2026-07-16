<?php
require_once __DIR__ . '/../oauth.php';
require_once __DIR__ . '/../mail.php';

$provider = isset($_GET['provider']) ? (string)$_GET['provider'] : '';
$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$flow = pw_oauth_take_flow($provider, $state);
if (!$flow) {
    pw_oauth_redirect('/index.html', 'google-failed');
}

$returnTo = $flow['return_to'];
if (isset($_GET['error'])) {
    pw_log_activity('google_oauth_cancelled', 'Google sign-in was cancelled or declined.', null, 'Google OAuth');
    pw_oauth_redirect($returnTo, $_GET['error'] === 'access_denied' ? 'google-cancelled' : 'google-failed');
}

$config = pw_oauth_provider_config($provider);
$code = isset($_GET['code']) ? (string)$_GET['code'] : '';
$profile = $config && $code !== '' ? pw_oauth_google_profile($config, $code, $flow['code_verifier']) : null;
if (!$profile) {
    pw_log_activity('google_oauth_failed', 'Google sign-in could not be verified.', null, 'Google OAuth');
    pw_oauth_redirect($returnTo, 'google-failed');
}

$db = pw_db();
$identityStmt = $db->prepare(
    'SELECT oi.id, oi.user_id, u.username, u.display_name, u.role, u.banned_at, u.banned_until
     FROM oauth_identities oi
     INNER JOIN users u ON u.id = oi.user_id
     WHERE oi.provider = ? AND oi.provider_subject = ?'
);
$identityStmt->execute([$provider, $profile['subject']]);
$identity = $identityStmt->fetch();

if ($flow['intent'] === 'link') {
    $currentUser = pw_current_user();
    if (!$currentUser || (int)$flow['link_user_id'] !== (int)$currentUser['id']) {
        pw_oauth_redirect('/profile.html', 'google-link-expired');
    }
    if ($identity && (int)$identity['user_id'] !== (int)$currentUser['id']) {
        pw_oauth_redirect('/profile.html', 'google-link-conflict');
    }
    if (!$identity) {
        $stmt = $db->prepare('INSERT INTO oauth_identities (user_id, provider, provider_subject, provider_email, last_used_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([(int)$currentUser['id'], $provider, $profile['subject'], $profile['email']]);
        pw_log_activity('google_linked', 'Linked a Google account for passwordless sign-in.', (int)$currentUser['id'], $currentUser['username']);
    } else {
        $db->prepare('UPDATE oauth_identities SET provider_email = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$profile['email'], $identity['id']]);
    }
    pw_oauth_redirect('/profile.html', 'google-linked');
}

if ($identity) {
    $user = $identity;
    if (pw_is_banned($user)) {
        pw_log_activity('google_login_banned', 'Google sign-in blocked: account is suspended.', (int)$user['user_id'], $user['username']);
        pw_oauth_redirect($returnTo, 'google-banned');
    }
    $db->prepare('UPDATE oauth_identities SET provider_email = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$profile['email'], $identity['id']]);
    $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')->execute([pw_client_ip(), $identity['user_id']]);
    $userId = (int)$identity['user_id'];
    $username = $identity['username'];
    $result = 'google-signed-in';
} else {
    $emailStmt = $db->prepare('SELECT id, username FROM users WHERE email = ?');
    $emailStmt->execute([$profile['email']]);
    $emailUser = $emailStmt->fetch();
    if ($emailUser) {
        pw_log_activity('google_link_required', 'Google sign-in needs confirmation from the existing password account.', (int)$emailUser['id'], $emailUser['username']);
        pw_oauth_redirect($returnTo, 'google-link-required');
    }

    $username = pw_oauth_username_from_email($profile['email']);
    $displayName = $profile['name'] !== '' ? $profile['name'] : $username;
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, NULL, ?)');
        $stmt->execute([$username, $profile['email'], $displayName]);
        $userId = (int)$db->lastInsertId();
        $stmt = $db->prepare('INSERT INTO oauth_identities (user_id, provider, provider_subject, provider_email, last_used_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([$userId, $provider, $profile['subject'], $profile['email']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        pw_log_activity('google_oauth_failed', 'Google account creation could not be completed.', null, 'Google OAuth');
        pw_oauth_redirect($returnTo, 'google-failed');
    }

    if (!empty($flow['import_avatar'])) {
        pw_oauth_import_google_avatar($userId, $profile['picture']);
    }
    pw_log_activity('google_registered', 'Created an account through Google sign-in.', $userId, $username);
    pw_send_template_email('welcome', $profile['email'], ['recipient_name' => $displayName, 'recipient_email' => $profile['email']]);
    $result = 'google-registered';
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
pw_issue_user_session($userId, 'google');
pw_log_activity('google_login', 'Signed in with Google.', $userId, $username);
pw_log_activity('session_created', 'Created a Google-authenticated session.', $userId, $username);
pw_oauth_redirect($returnTo, $result);
