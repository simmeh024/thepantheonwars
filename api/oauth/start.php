<?php
require_once __DIR__ . '/../oauth.php';

$provider = isset($_GET['provider']) ? (string)$_GET['provider'] : '';
$intent = isset($_GET['intent']) ? (string)$_GET['intent'] : 'login';
$returnTo = pw_oauth_safe_return_to($_GET['return_to'] ?? '/index.html');
$config = pw_oauth_provider_config($provider);

if (!$config) {
    pw_oauth_redirect($returnTo, 'google-not-configured');
}
if (!in_array($intent, ['login', 'register', 'link'], true)) {
    pw_oauth_redirect($returnTo, 'google-failed');
}

$linkUserId = null;
if ($intent === 'link') {
    $user = pw_require_login();
    $linkUserId = (int)$user['id'];
    $returnTo = '/profile.html';
}

$flow = pw_oauth_begin_flow(
    $provider,
    $intent,
    $returnTo,
    $intent === 'register' && isset($_GET['import_avatar']) && $_GET['import_avatar'] === '1',
    $linkUserId
);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $flow['code_verifier'], true)), '+/', '-_'), '=');
$query = http_build_query([
    'client_id' => $config['client_id'],
    'redirect_uri' => $config['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $flow['state'],
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'prompt' => 'select_account',
]);

header('Content-Type: text/html; charset=utf-8');
header('Location: ' . $config['authorize_url'] . '?' . $query, true, 303);
exit;
