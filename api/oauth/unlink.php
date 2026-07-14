<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$provider = isset($input['provider']) ? (string)$input['provider'] : '';
if ($provider !== 'google') {
    pw_error('That sign-in method is not supported.', 400);
}

$db = pw_db();
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$account = $stmt->fetch();
$stmt = $db->prepare('SELECT COUNT(*) AS count FROM oauth_identities WHERE user_id = ?');
$stmt->execute([$user['id']]);
$identityCount = (int)$stmt->fetch()['count'];
if (!$account || $account['password_hash'] === null || $account['password_hash'] === '') {
    if ($identityCount <= 1) {
        pw_error('Add a password before removing your only sign-in method.', 409);
    }
}

$stmt = $db->prepare('DELETE FROM oauth_identities WHERE user_id = ? AND provider = ?');
$stmt->execute([$user['id'], $provider]);
if ($stmt->rowCount() === 0) {
    pw_error('Google is not currently linked to this account.', 404);
}
pw_log_activity('google_unlinked', 'Removed the linked Google sign-in method.', (int)$user['id'], $user['username']);
pw_json(['ok' => true]);
