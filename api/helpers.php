<?php
/**
 * Shared helpers: session bootstrap, JSON responses, CSRF, auth guards.
 * Every api/*.php entry point should require this (it requires db.php too).
 */

require_once __DIR__ . '/db.php';

// --- Session bootstrap -----------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 days
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle session timeout: a logged-in session that hasn't made a single API
// request in 14 days is force-expired here, independent of the cookie's own
// 30-day lifetime. Narrows the exposure window if a session cookie is ever
// leaked (XSS, shared/public device) without making everyone re-login every
// visit. Anonymous (logged-out) sessions aren't affected -- there's nothing
// sensitive to expire until user_id is set.
if (!empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['pw_last_seen']) && (time() - $_SESSION['pw_last_seen']) > 14 * 24 * 60 * 60) {
        $_SESSION = [];
        session_destroy();
    } else {
        $_SESSION['pw_last_seen'] = time();
    }
}

header('Content-Type: application/json; charset=utf-8');

// --- GitHub API auth -----------------------------------------------------------
// Optional: define GITHUB_TOKEN in the outside-webroot secrets file (see
// config.sample.php) to authenticate GitHub REST API calls. Authenticated
// requests get 5,000 requests/hour instead of the unauthenticated primary
// rate limit of 60/hour -- see "Primary rate limit for authenticated users"
// at https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api
// Every call site below falls back to unauthenticated requests if the
// token isn't set, so this is safe to leave undefined.
function pw_github_curl_headers() {
    $headers = [
        'User-Agent: ThePantheonWars-AdminConsole',
        'Accept: application/vnd.github+json',
    ];
    if (defined('GITHUB_TOKEN') && GITHUB_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . GITHUB_TOKEN;
    }
    return $headers;
}

function pw_github_stream_header($userAgent = 'ThePantheonWars-Site') {
    $header = "User-Agent: {$userAgent}\r\nAccept: application/vnd.github+json\r\n";
    if (defined('GITHUB_TOKEN') && GITHUB_TOKEN !== '') {
        $header .= 'Authorization: Bearer ' . GITHUB_TOKEN . "\r\n";
    }
    return $header;
}

// --- Response helpers --------------------------------------------------------
function pw_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function pw_error($message, $status = 400) {
    pw_json(['ok' => false, 'error' => $message], $status);
}

function pw_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// --- CSRF --------------------------------------------------------------------
function pw_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function pw_require_csrf($input) {
    $token = isset($input['csrf']) ? $input['csrf'] : '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);
    }
}

// --- Auth guards ---------------------------------------------------------------
// A ban is only "active" if it was set AND (it's permanent, i.e. no expiry,
// OR the expiry hasn't passed yet). Once banned_until passes, the account is
// treated as unbanned everywhere without needing a cron job to clear it.
function pw_is_banned($user) {
    if (empty($user['banned_at'])) {
        return false;
    }
    if (empty($user['banned_until'])) {
        return true; // permanent
    }
    return strtotime($user['banned_until']) > time();
}

function pw_current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = pw_db()->prepare('SELECT id, username, email, display_name, overlord_affinity, role, created_at, banned_at, banned_until FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if (pw_is_banned($user)) {
        // Account was banned after this session was issued -- kill the
        // session immediately rather than letting it ride out.
        $_SESSION = [];
        session_destroy();
        return null;
    }
    return $user;
}

function pw_require_login() {
    $user = pw_current_user();
    if (!$user) {
        pw_error('You need to be logged in to do that.', 401);
    }
    return $user;
}

function pw_require_admin() {
    $user = pw_require_login();
    if ($user['role'] !== 'admin') {
        pw_error('Admins only.', 403);
    }
    return $user;
}

// Used by the Topic Reports section of the admin console, which moderators
// can also access (unlike the rest of the console, which stays admin-only).
function pw_require_mod_or_admin() {
    $user = pw_require_login();
    if (!in_array($user['role'], ['admin', 'moderator'], true)) {
        pw_error('Moderators and admins only.', 403);
    }
    return $user;
}

// --- Admin activity log ---------------------------------------------------
function pw_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $value = $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                // May contain a client,proxy1,proxy2 chain -- the first entry is the client.
                $parts = explode(',', $value);
                $value = trim($parts[0]);
            }
            return substr($value, 0, 64);
        }
    }
    return 'unknown';
}

// --- Login attempt tracking -------------------------------------------------
// Every login attempt (success or failure) is logged here, independent of
// the per-account failed_login_attempts/locked_until columns on users. This
// is what api/login.php's IP-based throttle reads from, and it doubles as
// an audit trail for non-admin accounts (admin logins already go through
// pw_log_admin_activity separately).
function pw_log_login_attempt($ip, $identifier, $success) {
    $stmt = pw_db()->prepare('INSERT INTO login_attempts (ip_address, identifier, success) VALUES (?, ?, ?)');
    $stmt->execute([$ip, substr($identifier, 0, 255), $success ? 1 : 0]);
    // Opportunistic prune (~2% of calls) since there's no dedicated cron for
    // this table -- keeps ~90 days of history without unbounded growth.
    if (random_int(1, 50) === 1) {
        pw_db()->exec('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');
    }
}

// --- Password strength -------------------------------------------------------
// Checks a password against the Have I Been Pwned breached-password corpus
// via the k-anonymity range API: only the first 5 hex chars of the
// password's SHA-1 hash are ever sent over the network, never the password
// or the full hash, so HIBP can't reconstruct which password was checked.
// Fails open (returns false, i.e. "not known to be pwned") on any network
// error -- a third-party API hiccup shouldn't block registration or a
// password change.
function pw_password_is_pwned($password) {
    $sha1 = strtoupper(sha1($password));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ThePantheonWars-Site\r\n",
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents('https://api.pwnedpasswords.com/range/' . $prefix, false, $context);
    if ($body === false) {
        return false;
    }
    foreach (explode("\n", $body) as $line) {
        $parts = explode(':', trim($line));
        if (count($parts) === 2 && strcasecmp($parts[0], $suffix) === 0) {
            return true;
        }
    }
    return false;
}

function pw_log_admin_activity($action, $description, $user = null) {
    $stmt = pw_db()->prepare(
        'INSERT INTO admin_activity_log (user_id, username, action, description, ip_address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user ? (int)$user['id'] : null,
        $user ? $user['username'] : 'unknown',
        $action,
        $description,
        pw_client_ip(),
    ]);
}

/**
 * Generates a random password of the given length using a CSPRNG
 * (random_int over /dev/urandom or the platform equivalent), pulled from a
 * charset that excludes visually ambiguous characters (0/O, 1/l/I) so an
 * admin reading it off screen to relay it to a member doesn't mistype it.
 * Guarantees at least one character from each class (upper, lower, digit,
 * symbol) so it can't accidentally roll an all-letters or all-digits result.
 */
function pw_generate_password($length = 14) {
    $classes = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '!@#$%^&*?',
    ];
    $all = implode('', $classes);
    $chars = [];
    foreach ($classes as $class) {
        $chars[] = $class[random_int(0, strlen($class) - 1)];
    }
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }
    return implode('', $chars);
}
