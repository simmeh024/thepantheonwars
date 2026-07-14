<?php
/**
 * Provider-neutral OAuth helpers. Google is the first configured provider;
 * future providers only need a config branch plus a verified-profile exchange
 * function, while state handling, linking, sessions, and audit behavior stay
 * shared here.
 */

require_once __DIR__ . '/helpers.php';

function pw_oauth_provider_config($provider) {
    if ($provider !== 'google') {
        return null;
    }

    if (!defined('GOOGLE_OAUTH_CLIENT_ID') || !defined('GOOGLE_OAUTH_CLIENT_SECRET') || !defined('GOOGLE_OAUTH_REDIRECT_URI')
        || GOOGLE_OAUTH_CLIENT_ID === '' || GOOGLE_OAUTH_CLIENT_SECRET === '' || GOOGLE_OAUTH_REDIRECT_URI === '') {
        return null;
    }

    return [
        'client_id' => GOOGLE_OAUTH_CLIENT_ID,
        'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_OAUTH_REDIRECT_URI,
        'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
    ];
}

function pw_oauth_safe_return_to($value) {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '' || strpos($value, '/') !== 0 || strpos($value, '//') === 0) {
        return '/index.html';
    }

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return '/index.html';
    }

    $path = isset($parts['path']) ? $parts['path'] : '/index.html';
    if ($path === '' || strpos($path, '/api/') === 0 || strpos($path, '/admin') === 0 || strpos($path, '..') !== false) {
        return '/index.html';
    }

    return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

function pw_oauth_redirect($returnTo, $result) {
    $parts = parse_url(pw_oauth_safe_return_to($returnTo));
    $path = $parts['path'] ?? '/index.html';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['oauth'] = $result;
    header('Content-Type: text/html; charset=utf-8');
    header('Location: ' . $path . '?' . http_build_query($query), true, 303);
    exit;
}

function pw_oauth_begin_flow($provider, $intent, $returnTo, $importAvatar = false, $linkUserId = null) {
    $state = bin2hex(random_bytes(32));
    $verifier = bin2hex(random_bytes(48));
    $_SESSION['pw_oauth_flow'] = [
        'provider' => $provider,
        'intent' => $intent,
        'state' => $state,
        'code_verifier' => $verifier,
        'return_to' => pw_oauth_safe_return_to($returnTo),
        'import_avatar' => $importAvatar ? 1 : 0,
        'link_user_id' => $linkUserId !== null ? (int)$linkUserId : null,
        'expires_at' => time() + 600,
    ];
    return $_SESSION['pw_oauth_flow'];
}

function pw_oauth_take_flow($provider, $state) {
    $flow = $_SESSION['pw_oauth_flow'] ?? null;
    unset($_SESSION['pw_oauth_flow']);

    if (!is_array($flow) || !isset($flow['state'], $flow['provider'], $flow['expires_at'])
        || $flow['provider'] !== $provider || !is_string($state)
        || !hash_equals($flow['state'], $state) || (int)$flow['expires_at'] < time()) {
        return null;
    }
    return $flow;
}

function pw_oauth_google_profile($config, $code, $verifier) {
    $tokenRequest = [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
        'code_verifier' => $verifier,
    ];
    $ch = curl_init($config['token_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($tokenRequest),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $token = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($token) || empty($token['access_token'])) {
        return null;
    }

    $ch = curl_init($config['userinfo_url']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token'], 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $profile = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($profile) || empty($profile['sub']) || empty($profile['email'])
        || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)
        || !filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return null;
    }

    return [
        'subject' => substr((string)$profile['sub'], 0, 255),
        'email' => strtolower(substr((string)$profile['email'], 0, 255)),
        'name' => substr(trim((string)($profile['name'] ?? '')), 0, 50),
        'picture' => isset($profile['picture']) ? substr((string)$profile['picture'], 0, 1000) : null,
    ];
}

function pw_oauth_username_from_email($email) {
    $local = preg_replace('/[^A-Za-z0-9_-]/', '', explode('@', $email)[0]);
    $base = substr($local ?: 'member', 0, 24);
    if (strlen($base) < 3) {
        $base .= 'member';
    }
    $base = substr($base, 0, 30);

    $db = pw_db();
    for ($suffix = 0; $suffix < 1000; $suffix++) {
        $candidate = $suffix === 0 ? $base : substr($base, 0, 30 - strlen((string)$suffix) - 1) . '-' . $suffix;
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }
    return 'member-' . bin2hex(random_bytes(4));
}

function pw_oauth_import_google_avatar($userId, $pictureUrl) {
    if (!is_string($pictureUrl) || $pictureUrl === '') {
        return false;
    }
    $parts = parse_url($pictureUrl);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/(^|\\.)googleusercontent\\.com$/', $host)) {
        return false;
    }

    $ch = curl_init($pictureUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($bytes) || strlen($bytes) === 0 || strlen($bytes) > 5 * 1024 * 1024) {
        return false;
    }

    $info = @getimagesizefromstring($bytes);
    $source = $info ? @imagecreatefromstring($bytes) : false;
    if (!$source) {
        return false;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $cropSize = min($sourceWidth, $sourceHeight);
    $dest = imagecreatetruecolor(400, 400);
    imagecopyresampled($dest, $source, 0, 0, (int)(($sourceWidth - $cropSize) / 2), (int)(($sourceHeight - $cropSize) / 2), 400, 400, $cropSize, $cropSize);
    imagedestroy($source);

    $directory = __DIR__ . '/../uploads/avatars';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        imagedestroy($dest);
        return false;
    }
    $path = $directory . '/' . (int)$userId . '.jpg';
    $temporary = $path . '.oauth.tmp';
    $written = imagejpeg($dest, $temporary, 85);
    imagedestroy($dest);
    if (!$written) {
        return false;
    }
    return rename($temporary, $path);
}
