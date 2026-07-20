<?php
require_once __DIR__ . '/../oauth.php';
require_once __DIR__ . '/../mail.php';

// Apple's response_mode=form_post POSTs state/code/error/user to this same
// URL instead of Google's GET redirect; merging both means the rest of this
// file can stay provider-neutral. $_GET always carries 'provider' (it's part
// of the redirect_uri query string for both providers), while 'user' (Apple's
// one-time name payload) only ever arrives via $_POST.
$params = $_POST + $_GET;

$provider = isset($params['provider']) ? (string)$params['provider'] : '';
$label = pw_oauth_provider_label($provider);
$state = isset($params['state']) ? (string)$params['state'] : '';
$flow = pw_oauth_take_flow($provider, $state);
if (!$flow) {
    pw_oauth_redirect('/index.html', $provider . '-failed');
}

$returnTo = $flow['return_to'];
if (isset($params['error'])) {
    pw_log_activity($provider . '_oauth_cancelled', $label . ' sign-in was cancelled or declined.', null, $label . ' OAuth');
    pw_oauth_redirect($returnTo, $params['error'] === 'access_denied' ? $provider . '-cancelled' : $provider . '-failed');
}

$config = pw_oauth_provider_config($provider);
$code = isset($params['code']) ? (string)$params['code'] : '';
$profile = null;
if ($config && $code !== '') {
    if ($provider === 'google') {
        $profile = pw_oauth_google_profile($config, $code, $flow['code_verifier']);
    } elseif ($provider === 'apple') {
        $profile = pw_oauth_apple_profile($config, $code, $flow['code_verifier'], $params['user'] ?? null);
    }
}
if (!$profile) {
    pw_log_activity($provider . '_oauth_failed', $label . ' sign-in could not be verified.', null, $label . ' OAuth');
    pw_oauth_redirect($returnTo, $provider . '-failed');
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
        pw_oauth_redirect('/profile.html', $provider . '-link-expired');
    }
    if ($identity && (int)$identity['user_id'] !== (int)$currentUser['id']) {
        pw_oauth_redirect('/profile.html', $provider . '-link-conflict');
    }
    if (!$identity) {
        $stmt = $db->prepare('INSERT INTO oauth_identities (user_id, provider, provider_subject, provider_email, last_used_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([(int)$currentUser['id'], $provider, $profile['subject'], $profile['email']]);
        // Both providers only reach this point after their own email_verified
        // check has passed. A linked address verifies the member's primary
        // email only when both addresses actually match.
        if (strcasecmp($profile['email'], $currentUser['email']) === 0) {
            try {
                $db->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([(int)$currentUser['id']]);
            } catch (Throwable $e) {
                // Keep linking available if this deployment is briefly ahead
                // of the manual schema migration.
            }
        }
        pw_log_activity($provider . '_linked', 'Linked a ' . $label . ' account for passwordless sign-in.', (int)$currentUser['id'], $currentUser['username']);
    } else {
        $db->prepare('UPDATE oauth_identities SET provider_email = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$profile['email'], $identity['id']]);
    }
    pw_oauth_redirect('/profile.html', $provider . '-linked');
}

if ($identity) {
    $user = $identity;
    if (pw_is_banned($user)) {
        pw_log_activity($provider . '_login_banned', $label . ' sign-in blocked: account is suspended.', (int)$user['user_id'], $user['username']);
        pw_oauth_redirect($returnTo, $provider . '-banned');
    }
    $db->prepare('UPDATE oauth_identities SET provider_email = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$profile['email'], $identity['id']]);
    $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')->execute([pw_client_ip(), $identity['user_id']]);
    $userId = (int)$identity['user_id'];
    $username = $identity['username'];
    $result = $provider . '-signed-in';
} else {
    $emailStmt = $db->prepare('SELECT id, username FROM users WHERE email = ?');
    $emailStmt->execute([$profile['email']]);
    $emailUser = $emailStmt->fetch();
    if ($emailUser) {
        pw_log_activity($provider . '_link_required', $label . ' sign-in needs confirmation from the existing password account.', (int)$emailUser['id'], $emailUser['username']);
        pw_oauth_redirect($returnTo, $provider . '-link-required');
    }

    $username = pw_oauth_username_from_email($profile['email']);
    $displayName = $profile['name'] !== '' ? $profile['name'] : $username;
    try {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO users (username, email, email_verified_at, password_hash, display_name) VALUES (?, ?, UTC_TIMESTAMP(), NULL, ?)');
            $stmt->execute([$username, $profile['email'], $displayName]);
        } catch (PDOException $e) {
            // Graceful compatibility while the code deployment is waiting for
            // its manual column migration; the user can still join through
            // this provider and the backfill will mark their matching email later.
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
            $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, NULL, ?)');
            $stmt->execute([$username, $profile['email'], $displayName]);
        }
        $userId = (int)$db->lastInsertId();
        $stmt = $db->prepare('INSERT INTO oauth_identities (user_id, provider, provider_subject, provider_email, last_used_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([$userId, $provider, $profile['subject'], $profile['email']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        pw_log_activity($provider . '_oauth_failed', $label . ' account creation could not be completed.', null, $label . ' OAuth');
        pw_oauth_redirect($returnTo, $provider . '-failed');
    }

    // Only Google ever supplies a profile picture to import.
    if ($provider === 'google' && !empty($flow['import_avatar'])) {
        pw_oauth_import_google_avatar($userId, $profile['picture']);
    }
    pw_log_activity($provider . '_registered', 'Created an account through ' . $label . ' sign-in.', $userId, $username);
    pw_send_template_email('welcome', $profile['email'], ['recipient_name' => $displayName, 'recipient_email' => $profile['email']]);
    $result = $provider . '-registered';
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
pw_issue_user_session($userId, $provider);
pw_log_activity($provider . '_login', 'Signed in with ' . $label . '.', $userId, $username);
pw_log_activity('session_created', 'Created a ' . $label . '-authenticated session.', $userId, $username);
pw_oauth_redirect($returnTo, $result);
