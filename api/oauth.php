<?php
/**
 * Provider-neutral OAuth helpers. Google and Apple are the configured
 * providers; a future provider only needs a config branch plus a
 * verified-profile exchange function, while state handling, linking,
 * sessions, and audit behavior stay shared here.
 */

require_once __DIR__ . '/helpers.php';

// Site Settings (Admin Console > System) lets an administrator turn each
// provider on/off independently of whether its credentials are configured --
// e.g. Apple can stay off until a Developer account exists, then be switched
// on later with no code deploy. Defaults (google on, apple off) are chosen so
// a missing/pre-migration app_settings table still matches today's real
// rollout state, the same fail-safe-default pattern as pw_mail_settings().
function pw_oauth_settings() {
    $settings = ['google' => true, 'apple' => false];
    try {
        $stmt = pw_db()->query("SELECT `key`, value FROM app_settings WHERE `key` IN ('oauth_google_enabled', 'oauth_apple_enabled')");
        foreach ($stmt->fetchAll() as $row) {
            if ($row['key'] === 'oauth_google_enabled') $settings['google'] = $row['value'] === '1';
            if ($row['key'] === 'oauth_apple_enabled') $settings['apple'] = $row['value'] === '1';
        }
    } catch (Throwable $e) {
        // migration_site_settings.sql may not have run yet -- keep the
        // defaults above rather than fatal on every OAuth-adjacent request.
    }
    return $settings;
}

function pw_oauth_provider_config($provider) {
    $enabledSettings = pw_oauth_settings();
    if (empty($enabledSettings[$provider])) {
        return null;
    }

    if ($provider === 'google') {
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

    if ($provider === 'apple') {
        if (!defined('APPLE_OAUTH_CLIENT_ID') || !defined('APPLE_OAUTH_TEAM_ID') || !defined('APPLE_OAUTH_KEY_ID')
            || !defined('APPLE_OAUTH_PRIVATE_KEY') || !defined('APPLE_OAUTH_REDIRECT_URI')
            || APPLE_OAUTH_CLIENT_ID === '' || APPLE_OAUTH_TEAM_ID === '' || APPLE_OAUTH_KEY_ID === ''
            || APPLE_OAUTH_PRIVATE_KEY === '' || APPLE_OAUTH_REDIRECT_URI === '') {
            return null;
        }

        return [
            'client_id' => APPLE_OAUTH_CLIENT_ID,
            'team_id' => APPLE_OAUTH_TEAM_ID,
            'key_id' => APPLE_OAUTH_KEY_ID,
            'private_key' => APPLE_OAUTH_PRIVATE_KEY,
            'redirect_uri' => APPLE_OAUTH_REDIRECT_URI,
            'authorize_url' => 'https://appleid.apple.com/auth/authorize',
            'token_url' => 'https://appleid.apple.com/auth/token',
        ];
    }

    return null;
}

// Display name for provider-labeled user-facing text and log messages.
function pw_oauth_provider_label($provider) {
    $labels = ['google' => 'Google', 'apple' => 'Apple'];
    return $labels[$provider] ?? ucfirst($provider);
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
    // OAuth can begin for a brand-new visitor, so it must deliberately create
    // the session that persists state and the PKCE verifier across redirects.
    pw_start_session();
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

function pw_oauth_base64url_encode($binary) {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function pw_oauth_base64url_decode($value) {
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($value, '-_', '+/'));
}

// openssl_sign() on an EC key returns an ASN.1 DER-encoded ECDSA signature
// (SEQUENCE of two INTEGERs), but a JWS ES256 signature must be the raw
// 64-byte concatenation of r and s (32 bytes each, left-padded/stripped of
// DER's sign-guard zero byte). P-256 signatures are always short enough for
// DER's short-form length byte, so no long-form length parsing is needed.
function pw_oauth_ecdsa_der_to_raw($der, $componentLength = 32) {
    $offset = 0;
    if (($der[$offset] ?? '') !== "\x30") return null;
    $offset += 2; // tag + short-form total-length byte
    if (($der[$offset] ?? '') !== "\x02") return null;
    $offset++;
    $rLen = ord($der[$offset] ?? "\x00");
    $offset++;
    $r = ltrim(substr($der, $offset, $rLen), "\x00");
    $offset += $rLen;
    if (($der[$offset] ?? '') !== "\x02") return null;
    $offset++;
    $sLen = ord($der[$offset] ?? "\x00");
    $offset++;
    $s = ltrim(substr($der, $offset, $sLen), "\x00");

    if (strlen($r) > $componentLength || strlen($s) > $componentLength) {
        return null;
    }
    return str_pad($r, $componentLength, "\x00", STR_PAD_LEFT) . str_pad($s, $componentLength, "\x00", STR_PAD_LEFT);
}

// Apple's token endpoint takes a signed JWT as the client secret instead of a
// static string -- there is nothing to store as a long-lived secret, so a
// fresh, short-lived one is minted for every sign-in attempt using the
// account's Sign In with Apple private key.
function pw_oauth_apple_client_secret($config) {
    $now = time();
    $signingInput = pw_oauth_base64url_encode(json_encode(['alg' => 'ES256', 'kid' => $config['key_id']]))
        . '.' . pw_oauth_base64url_encode(json_encode([
            'iss' => $config['team_id'],
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => $config['client_id'],
        ]));

    $privateKey = openssl_pkey_get_private($config['private_key']);
    if ($privateKey === false) {
        return null;
    }
    $derSignature = '';
    if (!openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $rawSignature = pw_oauth_ecdsa_der_to_raw($derSignature, 32);
    if ($rawSignature === null) {
        return null;
    }
    return $signingInput . '.' . pw_oauth_base64url_encode($rawSignature);
}

// $rawUserField is Apple's one-time 'user' POST field (JSON, name only --
// present only on the very first authorization ever granted to this app).
function pw_oauth_apple_profile($config, $code, $verifier, $rawUserField = null) {
    $clientSecret = pw_oauth_apple_client_secret($config);
    if ($clientSecret === null) {
        return null;
    }

    $tokenRequest = [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $clientSecret,
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
    if ($status !== 200 || !is_array($token) || empty($token['id_token'])) {
        return null;
    }

    // Apple has no separate userinfo REST call -- the identity claims are the
    // id_token JWT itself, delivered in this same direct, TLS-authenticated
    // response from Apple's own token endpoint. That direct server-to-server
    // connection is the trust boundary here, exactly like the Google flow
    // above trusting an access-token-authenticated REST response rather than
    // independently re-checking a signature -- so the payload is decoded,
    // not re-verified against Apple's JWKS.
    $parts = explode('.', $token['id_token']);
    if (count($parts) !== 3) {
        return null;
    }
    $claims = json_decode(pw_oauth_base64url_decode($parts[1]), true);
    if (!is_array($claims) || empty($claims['sub']) || empty($claims['email'])
        || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
        || ($claims['aud'] ?? null) !== $config['client_id']
        || ($claims['iss'] ?? null) !== 'https://appleid.apple.com'
        || (int)($claims['exp'] ?? 0) < time()
        || !filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return null;
    }

    $name = '';
    if (is_string($rawUserField) && $rawUserField !== '') {
        $userInfo = json_decode($rawUserField, true);
        if (is_array($userInfo) && is_array($userInfo['name'] ?? null)) {
            $name = trim((string)($userInfo['name']['firstName'] ?? '') . ' ' . (string)($userInfo['name']['lastName'] ?? ''));
        }
    }

    return [
        'subject' => substr((string)$claims['sub'], 0, 255),
        'email' => strtolower(substr((string)$claims['email'], 0, 255)),
        'name' => substr($name, 0, 50),
        'picture' => null, // Apple never provides a profile picture.
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
