<?php
require_once __DIR__ . '/../oauth.php';

$provider = isset($_GET['provider']) ? (string)$_GET['provider'] : '';
$intent = isset($_GET['intent']) ? (string)$_GET['intent'] : 'login';
$returnTo = pw_oauth_safe_return_to($_GET['return_to'] ?? '/index.html');
$config = pw_oauth_provider_config($provider);

if (!$config) {
    pw_oauth_redirect($returnTo, $provider . '-not-configured');
}
if (!in_array($intent, ['login', 'register', 'link'], true)) {
    pw_oauth_redirect($returnTo, $provider . '-failed');
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
$params = [
    'client_id' => $config['client_id'],
    'redirect_uri' => $config['redirect_uri'],
    'response_type' => 'code',
    'state' => $flow['state'],
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
];
if ($provider === 'apple') {
    // Apple requires response_mode=form_post whenever the requested scope
    // includes name/email -- it POSTs the result to the redirect URI instead
    // of a GET redirect, which api/oauth/callback.php reads alongside Google's
    // GET query string.
    $params['scope'] = 'name email';
    $params['response_mode'] = 'form_post';
} else {
    $params['scope'] = 'openid email profile';
    $params['prompt'] = 'select_account';
}
$query = http_build_query($params);

header('Content-Type: text/html; charset=utf-8');
header('Location: ' . $config['authorize_url'] . '?' . $query, true, 303);
exit;
