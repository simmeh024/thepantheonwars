<?php
/**
 * Shared helpers: session bootstrap, JSON responses, CSRF, auth guards.
 * Every api/*.php entry point should require this (it requires db.php too).
 */

require_once __DIR__ . '/db.php';

// Benchmarks can read this standard response header without receiving any
// application data. It reports the full PHP request duration plus aggregate
// database time/query count for endpoints that use this shared helper.
$GLOBALS['pw_request_started_at'] = hrtime(true);

// --- Session bootstrap -----------------------------------------------------
// A public page fires several independent API calls at once (visitor
// analytics, forum summaries, leaderboards, and the account session check).
// Starting a brand-new PHP session in every one of those requests races their
// Set-Cookie responses: a browser can retain session B while the login form
// received the CSRF token from session A. Start automatically only when an
// existing session cookie is present; routes that intentionally establish a
// session call pw_start_session() below.
function pw_start_session() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 days
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (isset($_COOKIE[session_name()])) {
    pw_start_session();
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

// Every API endpoint includes this helper, so keep the baseline browser
// protections here rather than relying on individual routes to remember them.
// JSON responses should never be MIME-sniffed or framed, and cross-origin
// navigations do not need the complete source URL as a referrer.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
    if (!headers_sent()) {
        $appMs = (hrtime(true) - $GLOBALS['pw_request_started_at']) / 1000000;
        $metrics = PW_PDO::request_metrics();
        header('Server-Timing: app;dur=' . round($appMs, 3)
            . ', db;dur=' . $metrics['db_ms']
            . ';desc="' . $metrics['queries'] . ' queries"');
    }
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
    pw_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function pw_require_csrf($input) {
    pw_start_session();
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
    try {
        $stmt = pw_db()->prepare('SELECT id, username, email, newsletter_subscribed, display_name, overlord_affinity, role, presence_status, reputation, selected_icon, created_at, banned_at, banned_until FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        // newsletter_subscribed/reputation/selected_icon arrive via manual
        // migrations after deploy (migration_newsletter_subscription.sql,
        // migration_reputation.sql, migration_reputation_icons.sql) -- this
        // function runs on every authenticated request, so it must keep
        // working during that window rather than fatal on a missing column.
        $stmt = pw_db()->prepare('SELECT id, username, email, display_name, overlord_affinity, role, presence_status, created_at, banned_at, banned_until FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $user['newsletter_subscribed'] = 1;
            $user['reputation'] = 0;
            $user['selected_icon'] = null;
        }
    }
    if (!$user) {
        return null;
    }
    if (!pw_validate_current_user_session((int)$user['id'])) {
        pw_destroy_local_session();
        return null;
    }
    if (pw_is_banned($user)) {
        // Account was banned after this session was issued -- kill the
        // session immediately rather than letting it ride out.
        pw_revoke_user_sessions((int)$user['id'], null, 'account_banned');
        pw_destroy_local_session();
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

// --- Fine-grained permissions ------------------------------------------------
// Returns the list of permission keys the given user's role grants, or ['*']
// for a superuser role (currently just 'admin') which short-circuits every
// pw_has_permission() check to true -- this is what guarantees a checkbox
// mistake in the Roles & Permissions admin UI can never lock every admin out.
// A user's effective permissions are the union of their main role
// (users.role -- also drives the public display color/rank) and any
// "other roles" held via the user_roles table. Any one of those roles
// being is_superuser grants full access, same as before this was multi-role.
// A user's full role slug set: their main role (users.role) plus any
// additional roles held via user_roles. Shared by pw_user_permissions()
// below and pw_can_see_board() (forum board visibility) so both read from
// one source of truth instead of each keeping their own copy.
function pw_user_role_slugs($user) {
    if (empty($user) || empty($user['role'])) {
        return [];
    }
    $slugs = [$user['role']];
    if (!empty($user['id'])) {
        $stmt = pw_db()->prepare('SELECT role_slug FROM user_roles WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $slugs[] = $r['role_slug'];
        }
    }
    return array_values(array_unique($slugs));
}

function pw_user_permissions($user) {
    $slugs = pw_user_role_slugs($user);
    if (empty($slugs)) {
        return [];
    }
    $db = pw_db();
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));

    $stmt = $db->prepare("SELECT 1 FROM roles WHERE slug IN ($placeholders) AND is_superuser = 1 LIMIT 1");
    $stmt->execute($slugs);
    if ($stmt->fetch()) {
        return ['*'];
    }
    $stmt = $db->prepare("SELECT DISTINCT permission_key FROM role_permissions WHERE role_slug IN ($placeholders)");
    $stmt->execute($slugs);
    return array_column($stmt->fetchAll(), 'permission_key');
}

// Forum board visibility: a board is visible if it's public, the visitor
// holds a superuser role (admin's existing "sees everything" behavior), or
// the visitor's role set intersects forum_board_roles for that board.
// $user may be null (guest) -- always safe, matches pw_has_permission()'s
// guest handling.
function pw_can_see_board($user, $board) {
    if (!empty($board['is_public'])) {
        return true;
    }
    $slugs = pw_user_role_slugs($user);
    if (empty($slugs)) {
        return false;
    }
    $db = pw_db();
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));

    $stmt = $db->prepare("SELECT 1 FROM roles WHERE slug IN ($placeholders) AND is_superuser = 1 LIMIT 1");
    $stmt->execute($slugs);
    if ($stmt->fetch()) {
        return true;
    }
    $stmt = $db->prepare("SELECT 1 FROM forum_board_roles WHERE board_id = ? AND role_slug IN ($placeholders) LIMIT 1");
    $stmt->execute(array_merge([$board['id']], $slugs));
    return (bool)$stmt->fetch();
}

// Visitor Statistics admin page: excludes page views attributed to a
// superuser (any role with is_superuser = 1, not just the literal 'admin'
// slug -- matches the same definition pw_user_permissions()/pw_can_see_board()
// use) unless the admin viewing the page has opted back in via the page's
// settings menu. $alias is the page_views table alias used in the calling
// query (defaults to no alias for queries that don't need one).
function pw_admin_view_filter_sql($alias = '') {
    $col = $alias === '' ? 'user_id' : "$alias.user_id";
    return "($col IS NULL OR $col NOT IN ("
        . "SELECT u.id FROM users u "
        . "LEFT JOIN user_roles ur ON ur.user_id = u.id "
        . "LEFT JOIN roles r1 ON r1.slug = u.role "
        . "LEFT JOIN roles r2 ON r2.slug = ur.role_slug "
        . "WHERE r1.is_superuser = 1 OR r2.is_superuser = 1"
        . "))";
}

// Shared lookup for the topics endpoints (create/list/get/move) that all
// need to resolve a board slug to a real forum_boards row before allowing
// an action against it.
function pw_forum_board_by_slug($slug) {
    $stmt = pw_db()->prepare('SELECT * FROM forum_boards WHERE slug = ?');
    $stmt->execute([$slug]);
    $board = $stmt->fetch();
    return $board ?: null;
}

function pw_has_permission($user, $key) {
    $perms = pw_user_permissions($user);
    return in_array('*', $perms, true) || in_array($key, $perms, true);
}

function pw_require_permission($key) {
    $user = pw_require_login();
    if (!pw_has_permission($user, $key)) {
        pw_error('You do not have permission to do that.', 403);
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

// Masks the last two octets of an IPv4 address (203.0.113.42 ->
// 203.0.xxx.xxx) or the last six groups of an IPv6 address, for display to
// admins who hold dashboards.view_ip_addresses -- keeps enough of the
// address to spot which network/region traffic is coming from without
// exposing the full address. Only used for *display*; stored/raw IPs
// (login_attempts, page_views) are untouched.
function pw_mask_ip($ip) {
    if ($ip === null || $ip === '') {
        return $ip;
    }
    if (strpos($ip, '.') !== false) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
        }
        return $ip;
    }
    if (strpos($ip, ':') !== false) {
        $parts = explode(':', $ip);
        if (count($parts) >= 2) {
            return $parts[0] . ':' . $parts[1] . ':xxxx:xxxx:xxxx:xxxx:xxxx:xxxx';
        }
    }
    return $ip;
}

/**
 * A member can choose Online, Away, or Inactive while signed in. Offline is
 * never stored or selectable; it is derived from the existing five-minute
 * activity window so stale or revoked sessions cannot claim an active state.
 */
function pw_public_presence_status($selectedStatus, $lastActiveAt): string {
    if (empty($lastActiveAt) || strtotime((string)$lastActiveAt) < time() - 300) {
        return 'offline';
    }
    $selectedStatus = strtolower(trim((string)$selectedStatus));
    return in_array($selectedStatus, ['online', 'away', 'inactive'], true) ? $selectedStatus : 'online';
}

/**
 * Reputation Levels, ordered by threshold ascending. Memoized per-request
 * (this can be called once per row in a topic/comment list). Returns an
 * empty array -- never throws -- if migration_reputation.sql hasn't run yet,
 * so reputation display fails open exactly like the other post-launch
 * migrations in this codebase.
 */
function pw_reputation_levels(): array {
    static $levels = null;
    if ($levels === null) {
        try {
            $levels = pw_db()->query('SELECT id, name, threshold, color FROM reputation_levels ORDER BY threshold ASC')->fetchAll();
        } catch (PDOException $e) {
            $levels = [];
        }
    }
    return $levels;
}

/**
 * Resolves a raw reputation point total into the ready-to-render fields the
 * front-end's reputation bar needs: current level name/color, the next
 * level's name/threshold (null once every level is reached), and the fill
 * percentage of progress toward it.
 */
function pw_reputation_info(int $reputation): array {
    $levels = pw_reputation_levels();
    $current = null;
    $currentNumber = null;
    $next = null;
    foreach ($levels as $index => $level) {
        if ((int)$level['threshold'] <= $reputation) {
            $current = $level;
            $currentNumber = $index + 1;
        } elseif ($next === null) {
            $next = $level;
        }
    }

    $progress = 100;
    if ($current && $next) {
        $span = (int)$next['threshold'] - (int)$current['threshold'];
        $progress = $span > 0 ? (int)round((($reputation - (int)$current['threshold']) / $span) * 100) : 100;
        $progress = max(0, min(100, $progress));
    } elseif (!$current) {
        $progress = 0;
    }

    return [
        'points' => $reputation,
        'level_name' => $current ? $current['name'] : null,
        // Rank position within the ladder (1 = the lowest level), shown as
        // the number inside the reputation bar's square.
        'level_number' => $currentNumber,
        'level_color' => $current ? $current['color'] : '#c7ccd6',
        'next_level_name' => $next ? $next['name'] : null,
        'next_level_threshold' => $next ? (int)$next['threshold'] : null,
        'progress_percent' => $progress,
    ];
}

/**
 * Fixed 6-icon Overlord resonance catalog, in the same order as (and keyed
 * to) the hardcoded overlord list already used by quiz.html and this
 * file's own $validOverlords in api/save-quiz-result.php. Not admin-
 * manageable -- deliberately as static as that existing list.
 */
function pw_overlord_icon_keys(): array {
    return ['syn-dravus', 'malric-thorne', 'korrus-vale', 'lysara-venthe', 'zura-kaleth', 'maerion-thal'];
}

/**
 * Unlocks an Overlord resonance icon for a user the first time they reach
 * it (a 100% "Pure Resonance" quiz result) and notifies them. A second
 * unlock attempt for the same icon is a silent no-op -- checked explicitly
 * rather than relying on the unique key, so the notification only ever
 * fires once. Best-effort: fails open if migration_reputation_icons.sql
 * hasn't run yet.
 */
function pw_unlock_overlord_icon(int $userId, string $iconKey, string $overlordName): void {
    try {
        $db = pw_db();
        $stmt = $db->prepare('SELECT id FROM user_unlocked_icons WHERE user_id = ? AND icon_key = ?');
        $stmt->execute([$userId, $iconKey]);
        if ($stmt->fetch()) {
            return;
        }
        $db->prepare('INSERT INTO user_unlocked_icons (user_id, icon_key) VALUES (?, ?)')->execute([$userId, $iconKey]);
        pw_notify($userId, 'icon_unlocked', null, null, null, null, $overlordName);
    } catch (PDOException $e) {
        // migration_reputation_icons.sql may be run after code deployment.
    }
}

function pw_mark_user_offline_if_no_active_sessions(int $userId): void {
    try {
        $stmt = pw_db()->prepare(
            'SELECT 1 FROM user_sessions
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
               AND last_active_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            pw_db()->prepare('UPDATE users SET last_active_at = NULL WHERE id = ?')->execute([$userId]);
        }
    } catch (Throwable $e) {
        // Session presence is an enhancement. A pending session migration must
        // never make sign-out fail.
    }
}

// Maps only explicitly recognised crawler User-Agent signatures to a readable
// name for analytics display. Keep this deliberately allowlisted: an unknown
// bot remains a normal Guest, rather than incorrectly changing visitor
// classification based on a broad "bot" match. Add new signatures here as
// search engines introduce them.
function pw_crawler_name($userAgent) {
    if (!is_string($userAgent) || $userAgent === '') {
        return null;
    }

    static $signatures = [
        'googlebot' => 'Googlebot',
        'bingbot' => 'Bingbot',
        'duckduckbot' => 'DuckDuckBot',
        'yandexbot' => 'YandexBot',
        'baiduspider' => 'Baiduspider',
        'applebot' => 'Applebot',
        'yahoo! slurp' => 'Yahoo! Slurp',
        'sogou' => 'Sogou Spider',
        'seznambot' => 'SeznamBot',
        'petalbot' => 'PetalBot',
        'naverbot' => 'Naverbot',
        'google-inspectiontool' => 'Google Inspection Tool',
    ];

    $normalized = strtolower($userAgent);
    foreach ($signatures as $signature => $name) {
        if (strpos($normalized, $signature) !== false) {
            return $name;
        }
    }

    return null;
}

// Resolves an IP to a [country_code, country_name] pair for the Visitor
// Statistics "Traffic by Country" card and the Recent Visits country tag.
// Checks the ip_country_cache table first (an IP's country is looked up at
// most once, ever); on a cache miss, calls the free ip-api.com lookup
// (no key required, ~45 req/min limit -- fine given the cache) with a short
// timeout so a slow/unreachable lookup can't meaningfully delay the
// fire-and-forget track-visit.php beacon. Private/reserved/invalid IPs
// (localhost, LAN testing, 'unknown') are never looked up and always
// resolve to [null, null].
function pw_resolve_country($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return [null, null];
    }

    $db = pw_db();
    $stmt = $db->prepare('SELECT country_code, country_name FROM ip_country_cache WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $cached = $stmt->fetch();
    if ($cached) {
        return [$cached['country_code'], $cached['country_name']];
    }

    $code = null;
    $name = null;
    $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,country');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (is_array($data) && ($data['status'] ?? null) === 'success') {
            $code = isset($data['countryCode']) ? substr((string)$data['countryCode'], 0, 2) : null;
            $name = isset($data['country']) ? substr((string)$data['country'], 0, 100) : null;
        }
    }

    if ($code !== null) {
        $insertStmt = $db->prepare(
            'INSERT INTO ip_country_cache (ip_address, country_code, country_name) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name)'
        );
        $insertStmt->execute([$ip, $code, $name]);
    }

    return [$code, $name];
}

// --- Account session registry ----------------------------------------------
// PHP keeps the authenticated browser state in its normal secure cookie. This
// registry adds a revocable, database-backed record alongside it. The browser
// only receives an opaque random identifier inside the PHP session; the DB
// stores a SHA-256 hash, never a raw PHP session ID or registry token.
function pw_current_session_token() {
    return isset($_SESSION['pw_session_token']) && is_string($_SESSION['pw_session_token'])
        ? $_SESSION['pw_session_token'] : null;
}

function pw_session_hash($value) {
    return hash('sha256', (string)$value);
}

function pw_session_client_details() {
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $browser = 'Unknown browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Microsoft Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS/') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';

    $os = 'Unknown operating system';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return [$ua, $browser, $os, $browser . ' on ' . $os];
}

function pw_issue_user_session($userId, $provider = 'password') {
    $token = bin2hex(random_bytes(32));
    $_SESSION['pw_session_token'] = $token;
    $_SESSION['pw_authenticated_at'] = time();
    $_SESSION['pw_session_provider'] = $provider;
    try {
        [$ua, $browser, $os, $label] = pw_session_client_details();
        $ip = pw_client_ip();
        // Reuse any country already learned by visit analytics, but never make
        // sign-in wait on a third-party geolocation request.
        $countryCode = null;
        $countryName = null;
        $countryStmt = pw_db()->prepare('SELECT country_code, country_name FROM ip_country_cache WHERE ip_address = ?');
        $countryStmt->execute([$ip]);
        if ($country = $countryStmt->fetch()) {
            $countryCode = $country['country_code'];
            $countryName = $country['country_name'];
        }
        $stmt = pw_db()->prepare(
            'INSERT INTO user_sessions (user_id, session_token_hash, php_session_id_hash, device_label, user_agent, browser_name, operating_system, ip_address, country_code, country_name, auth_provider, expires_at, is_persistent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY), 1)'
        );
        $stmt->execute([$userId, pw_session_hash($token), pw_session_hash(session_id()), $label, $ua, $browser, $os, $ip, $countryCode, $countryName, $provider]);
    } catch (Throwable $e) {
        // The registry migration may not have run yet. Do not break a valid
        // login during deployment; once present, validation becomes strict.
    }
    return $token;
}

function pw_validate_current_user_session($userId) {
    $token = pw_current_session_token();
    if ($token === null) {
        pw_issue_user_session($userId, 'password'); // transparent legacy-session adoption
        return true;
    }
    try {
        $stmt = pw_db()->prepare(
            'SELECT id, last_active_at FROM user_sessions
             WHERE user_id = ? AND session_token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([$userId, pw_session_hash($token)]);
        $row = $stmt->fetch();
        if (!$row) return false;
        if (empty($row['last_active_at']) || strtotime($row['last_active_at']) < time() - 300) {
            pw_db()->prepare('UPDATE user_sessions SET last_active_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
        }
        return true;
    } catch (Throwable $e) {
        return true; // fail open only while the optional migration is absent
    }
}

function pw_revoke_user_sessions($userId, $exceptToken = null, $reason = 'user_requested') {
    try {
        $sql = 'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
        $params = [$reason, $userId];
        if ($exceptToken !== null) {
            $sql .= ' AND session_token_hash != ?';
            $params[] = pw_session_hash($exceptToken);
        }
        $stmt = pw_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function pw_destroy_local_session() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
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

// $userId must be a real users.id or null (the column has an ON DELETE SET
// NULL foreign key -- inserting 0 or any other non-existent id would fail).
// Used directly by login.php for events that may not have a real account
// behind them yet (unknown identifier, IP-level throttle).
function pw_log_activity($action, $description, $userId, $username) {
    $stmt = pw_db()->prepare(
        'INSERT INTO admin_activity_log (user_id, username, action, description, ip_address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $username, $action, $description, pw_client_ip()]);
}

function pw_log_admin_activity($action, $description, $user = null) {
    pw_log_activity($action, $description, $user ? (int)$user['id'] : null, $user ? $user['username'] : 'unknown');
}

// --- Notifications -----------------------------------------------------
// Per-user opt-out flags, one row per user in notification_preferences
// (columns notif_like/notif_mention/notif_quote/notif_report_resolved and
// the announcement types). A missing row (the common case -- most users never
// touch Notification Settings) means every type remains enabled, including
// newly introduced ones, so this only ever needs to read, never backfill on
// account creation.
function pw_notifications_enabled($userId, $type) {
    $columns = ['like' => 'notif_like', 'mention' => 'notif_mention', 'quote' => 'notif_quote', 'report_resolved' => 'notif_report_resolved', 'world_available' => 'notif_world_available', 'news_published' => 'notif_news_published', 'topic_reply' => 'notif_topic_reply', 'icon_unlocked' => 'notif_icon_unlocked'];
    if (!isset($columns[$type])) {
        return true;
    }
    $column = $columns[$type];
    $stmt = pw_db()->prepare("SELECT $column FROM notification_preferences WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return true;
    }
    return (bool)$row[$column];
}

// Centralizes writes to the notifications table -- see api/messages/like.php
// (like), api/topics/create.php + api/comments/post.php (mention, quote),
// and api/admin/topic-reports/resolve.php (report_resolved) for call sites.
// Silently no-ops if the recipient is also the actor, so liking/mentioning/
// quoting your own content never notifies yourself, and also no-ops if the
// recipient has turned this notification type off in Notification Settings.
function pw_notify($userId, $type, $actorUserId = null, $topicId = null, $commentId = null, $reportId = null, $excerpt = null, $worldId = null, $newsSlug = null) {
    if ($actorUserId !== null && (int)$actorUserId === (int)$userId) {
        return;
    }
    if (!pw_notifications_enabled($userId, $type)) {
        return;
    }
    $stmt = pw_db()->prepare(
        'INSERT INTO notifications (user_id, type, actor_user_id, topic_id, comment_id, report_id, world_id, news_slug, excerpt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $type,
        $actorUserId,
        $topicId,
        $commentId,
        $reportId,
        $worldId,
        $newsSlug !== null ? substr($newsSlug, 0, 120) : null,
        $excerpt !== null ? substr($excerpt, 0, 200) : null,
    ]);
}

/**
 * The fixed activity awards shown in Reputation Control. Keeping this catalog
 * server-side means the admin view and the actual award paths share one
 * authoritative description of the live system.
 */
function pw_reputation_reward_catalog(): array {
    $defaults = [
        ['key' => 'topic_created', 'label' => 'Start a forum topic', 'points' => 1],
        ['key' => 'comment_posted', 'label' => 'Post a forum reply', 'points' => 1],
        ['key' => 'content_liked', 'label' => 'Receive a like', 'points' => 2],
        ['key' => 'quiz_completed', 'label' => 'Complete the Overlord quiz (first time)', 'points' => 10],
        ['key' => 'book_started', 'label' => 'Start a book (first time)', 'points' => 3],
        ['key' => 'book_finished', 'label' => 'Finish a book (first time)', 'points' => 5],
        ['key' => 'news_comment_posted', 'label' => 'Comment on a newspost', 'points' => 1],
    ];
    try {
        $rows = pw_db()->query('SELECT `key`, label, base_points, is_enabled FROM reputation_reward_rules ORDER BY `key` ASC')->fetchAll();
        if (!$rows) return $defaults;
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = ['key' => $row['key'], 'label' => $row['label'], 'points' => (int)$row['base_points'], 'enabled' => (bool)$row['is_enabled']];
        }
        foreach ($defaults as &$default) {
            if (isset($byKey[$default['key']])) $default = $byKey[$default['key']];
            else $default['enabled'] = true;
        }
    } catch (Throwable $e) {
        // The code remains compatible until the expansion migration is run.
    }
    return $defaults;
}

/**
 * Reads the currently configured temporary reputation multiplier. Expiry is
 * enforced at the point of every award, so no cron job is needed to switch a
 * finished event off. Settings are optional during deployment and safely
 * fall back to normal 1x awards if app_settings is not available yet.
 */
function pw_reputation_multiplier_config(?string $rewardKey = null): array {
    $config = ['multiplier' => 1, 'ends_at' => null, 'starts_at' => null, 'is_active' => false, 'event_name' => null, 'event_id' => null];
    try {
        $events = pw_db()->query("SELECT id, name, multiplier, starts_at, ends_at, reward_keys_json FROM reputation_events WHERE is_enabled = 1 AND starts_at <= UTC_TIMESTAMP() AND ends_at > UTC_TIMESTAMP() ORDER BY multiplier DESC, ends_at ASC")->fetchAll();
        foreach ($events as $event) {
            $keys = json_decode($event['reward_keys_json'], true);
            if (!is_array($keys) || !$keys || $rewardKey === null || in_array($rewardKey, $keys, true)) {
                return ['multiplier' => (int)$event['multiplier'], 'ends_at' => $event['ends_at'], 'starts_at' => $event['starts_at'], 'is_active' => true, 'event_name' => $event['name'], 'event_id' => (int)$event['id']];
            }
        }
    } catch (Throwable $e) {
        // Fall through to the previous single-event setting during rollout.
    }
    try {
        $stmt = pw_db()->query("SELECT `key`, value FROM app_settings WHERE `key` IN ('reputation_multiplier', 'reputation_multiplier_ends_at')");
        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[$row['key']] = (string)$row['value'];
        }
        $multiplier = isset($values['reputation_multiplier']) ? (int)$values['reputation_multiplier'] : 1;
        $endsAt = isset($values['reputation_multiplier_ends_at']) ? trim($values['reputation_multiplier_ends_at']) : '';
        $endsTimestamp = $endsAt !== '' ? strtotime($endsAt . ' UTC') : false;
        if (in_array($multiplier, [2, 3, 4], true) && $endsTimestamp !== false && $endsTimestamp > time()) {
            return [
                'multiplier' => $multiplier,
                'ends_at' => gmdate('Y-m-d H:i:s', $endsTimestamp),
                'starts_at' => null,
                'is_active' => true,
                'event_name' => 'Legacy multiplier event',
                'event_id' => null,
            ];
        }
    } catch (Throwable $e) {
        // app_settings may not have been migrated yet; normal awards remain safe.
    }
    return $config;
}

/**
 * Applies the active event multiplier and awards the resulting amount. Call
 * this only for positive, earned reputation; reversible awards such as likes
 * persist the returned value and subtract that exact amount on reversal.
 */
function pw_award_reputation(PDO $db, int $userId, int $basePoints, string $rewardKey = 'manual', array $meta = []): int {
    if ($userId <= 0 || $basePoints <= 0) {
        return 0;
    }
    $label = isset($meta['label']) ? (string)$meta['label'] : $rewardKey;
    foreach (pw_reputation_reward_catalog() as $rule) {
        if ($rule['key'] === $rewardKey) {
            if (isset($rule['enabled']) && !$rule['enabled']) return 0;
            $basePoints = (int)$rule['points'];
            $label = $rule['label'];
            break;
        }
    }
    $config = pw_reputation_multiplier_config($rewardKey);
    $awarded = $basePoints * (int)$config['multiplier'];
    $stmt = $db->prepare('UPDATE users SET reputation = reputation + ? WHERE id = ?');
    $stmt->execute([$awarded, $userId]);
    try {
        $ledger = $db->prepare('INSERT INTO reputation_ledger (user_id, actor_user_id, reward_key, label, base_points, multiplier, points, source_type, source_id, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ledger->execute([$userId, $meta['actor_user_id'] ?? null, $rewardKey, substr($label, 0, 140), $basePoints, (int)$config['multiplier'], $awarded, $meta['source_type'] ?? null, $meta['source_id'] ?? null, $meta['note'] ?? null]);
        pw_evaluate_reputation_achievements($db, $userId);
    } catch (Throwable $e) {
        // Ledger/achievement tables are optional until the migration is run.
    }
    return $awarded;
}

function pw_remove_reputation(PDO $db, int $userId, int $points, array $meta = []): void {
    if ($userId <= 0 || $points <= 0) {
        return;
    }
    $stmt = $db->prepare('UPDATE users SET reputation = GREATEST(0, reputation - ?) WHERE id = ?');
    $stmt->execute([$points, $userId]);
    try {
        $ledger = $db->prepare('INSERT INTO reputation_ledger (user_id, actor_user_id, reward_key, label, base_points, multiplier, points, source_type, source_id, note) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)');
        $ledger->execute([$userId, $meta['actor_user_id'] ?? null, $meta['reward_key'] ?? 'reversal', $meta['label'] ?? 'Reputation reversal', -$points, -$points, $meta['source_type'] ?? null, $meta['source_id'] ?? null, $meta['note'] ?? null]);
    } catch (Throwable $e) {}
}

function pw_reputation_achievement_catalog(): array {
    return [
        ['key' => 'first_signal', 'name' => 'First Signal', 'description' => 'Started your first forum topic.', 'points' => 5, 'tier' => 'bronze', 'progress_type' => 'topics', 'target' => 1, 'icon' => '✦', 'track' => 'forum_topics', 'track_label' => 'Forum Topics', 'track_order' => 1],
        ['key' => 'topic_vanguard', 'name' => 'Topic Vanguard', 'description' => 'Started fifty forum topics.', 'points' => 50, 'tier' => 'silver', 'progress_type' => 'topics', 'target' => 50, 'icon' => '◈', 'track' => 'forum_topics', 'track_label' => 'Forum Topics', 'track_order' => 2],
        ['key' => 'topic_architect', 'name' => 'Topic Architect', 'description' => 'Started two hundred and fifty forum topics.', 'points' => 100, 'tier' => 'gold', 'progress_type' => 'topics', 'target' => 250, 'icon' => '◆', 'track' => 'forum_topics', 'track_label' => 'Forum Topics', 'track_order' => 3],
        ['key' => 'nexus_sovereign', 'name' => 'Nexus Sovereign', 'description' => 'Started two thousand forum topics.', 'points' => 1000, 'tier' => 'prismatic', 'progress_type' => 'topics', 'target' => 2000, 'icon' => '✧', 'track' => 'forum_topics', 'track_label' => 'Forum Topics', 'track_order' => 4],
        ['key' => 'nexus_voice', 'name' => 'Nexus Voice', 'description' => 'Published ten forum posts.', 'points' => 15, 'tier' => 'silver', 'progress_type' => 'posts', 'target' => 10, 'icon' => '◈'],
        ['key' => 'resonance_awakened', 'name' => 'Resonance Awakened', 'description' => 'Completed the Overlord quiz.', 'points' => 10, 'tier' => 'bronze', 'progress_type' => 'quiz', 'target' => 1, 'icon' => '◉'],
        ['key' => 'shelf_seeker', 'name' => 'Shelf Seeker', 'description' => 'Started three books.', 'points' => 5, 'tier' => 'bronze', 'progress_type' => 'books_started', 'target' => 3, 'icon' => '▰'],
        ['key' => 'seven_books_finished', 'name' => 'Seven Worlds Read', 'description' => 'Finished seven books.', 'points' => 50, 'tier' => 'gold', 'progress_type' => 'books_finished', 'target' => 7, 'icon' => '◫'],
        ['key' => 'saga_finisher', 'name' => 'Saga Finisher', 'description' => 'Finished all fourteen books.', 'points' => 100, 'tier' => 'prismatic', 'progress_type' => 'books_finished', 'target' => 14, 'icon' => '◆'],
    ];
}

function pw_evaluate_reputation_achievements(PDO $db, int $userId): void {
    // Queries below are intentionally separate: missing optional tables must not block an award.
    $topicStmt = $db->prepare('SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0'); $topicStmt->execute([$userId]);
    $postStmt = $db->prepare('SELECT (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) + (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0)'); $postStmt->execute([$userId, $userId]);
    $quizStmt = $db->prepare('SELECT COUNT(*) FROM quiz_results WHERE user_id = ?'); $quizStmt->execute([$userId]);
    $bookStmt = $db->prepare('SELECT COUNT(*) FROM user_book_progress WHERE user_id = ? AND started_at IS NOT NULL'); $bookStmt->execute([$userId]);
    $finishStmt = $db->prepare('SELECT COUNT(*) FROM user_book_progress WHERE user_id = ? AND finished_at IS NOT NULL'); $finishStmt->execute([$userId]);
    $topicCount = (int)$topicStmt->fetchColumn(); $postCount = (int)$postStmt->fetchColumn(); $quizCount = (int)$quizStmt->fetchColumn(); $bookCount = (int)$bookStmt->fetchColumn(); $finishCount = (int)$finishStmt->fetchColumn();
    $unlocks = [
        'first_signal' => $topicCount >= 1,
        'topic_vanguard' => $topicCount >= 50,
        'topic_architect' => $topicCount >= 250,
        'nexus_sovereign' => $topicCount >= 2000,
        'nexus_voice' => $postCount >= 10,
        'resonance_awakened' => $quizCount >= 1,
        'shelf_seeker' => $bookCount >= 3,
        'seven_books_finished' => $finishCount >= 7,
        'saga_finisher' => $finishCount >= 14,
    ];
    $insert = $db->prepare('INSERT IGNORE INTO user_reputation_achievements (user_id, achievement_key) VALUES (?, ?)');
    $alreadyRewarded = $db->prepare('SELECT 1 FROM reputation_ledger WHERE user_id = ? AND reward_key = ? LIMIT 1');
    foreach (pw_reputation_achievement_catalog() as $achievement) {
        $key = $achievement['key'];
        if (empty($unlocks[$key])) continue;
        $insert->execute([$userId, $key]);
        $alreadyRewarded->execute([$userId, 'achievement_' . $key]);
        if (!$alreadyRewarded->fetchColumn()) {
            pw_award_reputation($db, $userId, (int)$achievement['points'], 'achievement_' . $key, ['label' => $achievement['name'] . ' achievement', 'source_type' => 'achievement', 'note' => $achievement['description']]);
        }
    }
}

function pw_award_daily_return(PDO $db, int $userId): int {
    try {
        $claim = $db->prepare('UPDATE users SET last_reputation_return_at = UTC_TIMESTAMP() WHERE id = ? AND created_at <= UTC_TIMESTAMP() - INTERVAL 1 DAY AND (last_reputation_return_at IS NULL OR last_reputation_return_at <= UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        $claim->execute([$userId]);
        if ($claim->rowCount() !== 1) return 0;
        return pw_award_reputation($db, $userId, 2, 'daily_return', ['label' => 'Returned after a day', 'source_type' => 'daily_return']);
    } catch (Throwable $e) { return 0; }
}

// Direct-message alerts are intentionally collapsed per conversation. A busy
// exchange therefore remains one useful bell item rather than becoming a
// notification for every individual line. Direct messages are core site
// functionality, so unlike optional announcement types this helper does not
// consult a user preference.
function pw_notify_direct_message($userId, $actorUserId, $conversationId, $messageId, $excerpt) {
    if ((int)$userId === (int)$actorUserId) {
        return;
    }
    $db = pw_db();
    $stmt = $db->prepare(
        'SELECT id FROM notifications WHERE user_id = ? AND type = "direct_message" AND conversation_id = ? LIMIT 1'
    );
    $stmt->execute([$userId, $conversationId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE notifications
             SET actor_user_id = ?, direct_message_id = ?, excerpt = ?, is_read = 0, created_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$actorUserId, $messageId, mb_substr($excerpt, 0, 200), $existing['id']]);
        return;
    }
    $stmt = $db->prepare(
        'INSERT INTO notifications (user_id, type, actor_user_id, conversation_id, direct_message_id, excerpt)
         VALUES (?, "direct_message", ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $actorUserId, $conversationId, $messageId, mb_substr($excerpt, 0, 200)]);
}

// A staff sender (main role or additional role) may always contact a member.
// The override is deliberately one-way: a member's block still prevents that
// member from sending ordinary follow-up messages to the staff account.
function pw_is_staff_messenger($user) {
    return !empty(array_intersect(pw_user_role_slugs($user), ['admin', 'moderator']));
}

function pw_direct_messages_blocked($senderUser, $recipientId) {
    if (pw_is_staff_messenger($senderUser)) {
        return false;
    }
    $stmt = pw_db()->prepare(
        'SELECT 1 FROM user_blocks
         WHERE (blocker_user_id = ? AND blocked_user_id = ?)
            OR (blocker_user_id = ? AND blocked_user_id = ?)
         LIMIT 1'
    );
    $stmt->execute([(int)$senderUser['id'], (int)$recipientId, (int)$recipientId, (int)$senderUser['id']]);
    return (bool)$stmt->fetch();
}

// Broadcasts a "world_available" notification to every non-banned member
// (skipping anyone who has turned this type off in Notification Settings,
// via pw_notify()'s own per-user pw_notifications_enabled() check) --
// called from api/admin/worlds/update.php only on the transition into
// status = 'available', never on every save.
function pw_notify_world_available($worldId) {
    $db = pw_db();
    $stmt = $db->query(
        "SELECT id FROM users WHERE banned_at IS NULL OR (banned_until IS NOT NULL AND banned_until <= NOW())"
    );
    foreach ($stmt->fetchAll() as $row) {
        pw_notify((int)$row['id'], 'world_available', null, null, null, null, null, $worldId);
    }
}

// Broadcasts a news-publication notice to eligible members. The article title
// is kept as the notification excerpt so both the bell and the full history
// can render the same stable reader-facing message, while the stored slug
// takes the reader directly to the dedicated news article.
function pw_notify_news_published($actorUserId, $title, $slug) {
    $db = pw_db();
    $stmt = $db->prepare(
        'SELECT id FROM users
         WHERE (banned_at IS NULL OR (banned_until IS NOT NULL AND banned_until <= NOW()))
           AND id != ?'
    );
    $stmt->execute([(int)$actorUserId]);
    foreach ($stmt->fetchAll() as $row) {
        pw_notify((int)$row['id'], 'news_published', (int)$actorUserId, null, null, null, $title, null, $slug);
    }
}

// Likes are collapsed into a single evolving notification per (recipient,
// target) rather than one row per like -- so "Andrea liked your post" and
// a later like from someone else on that same post update the same row
// (bumping it back to unread/most-recent) instead of spamming a fresh row
// per liker. api/notifications/list.php separately counts how many total
// likers a target has (via message_likes) to render "X and N others liked
// your post". Uses the null-safe <=> operator since topic_id/comment_id
// are mutually exclusive (one is always NULL depending on target type).
function pw_notify_like($userId, $actorUserId, $topicId, $commentId) {
    if ((int)$userId === (int)$actorUserId) {
        return;
    }
    if (!pw_notifications_enabled($userId, 'like')) {
        return;
    }
    $db = pw_db();
    $stmt = $db->prepare(
        'SELECT id FROM notifications WHERE user_id = ? AND type = "like" AND topic_id <=> ? AND comment_id <=> ?'
    );
    $stmt->execute([$userId, $topicId, $commentId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE notifications SET actor_user_id = ?, is_read = 0, created_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$actorUserId, $existing['id']]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_id, type, actor_user_id, topic_id, comment_id) VALUES (?, "like", ?, ?, ?)'
        );
        $stmt->execute([$userId, $actorUserId, $topicId, $commentId]);
    }
}

// Extracts unique @username mentions from a post/comment body, resolved
// against real users.username values (case-insensitive). Quoted text is
// stripped first so requoting an old message doesn't re-notify whoever it
// originally mentioned. $excludeUserId (the author) is always dropped from
// the result, since mentioning yourself shouldn't notify you either.
function pw_extract_mentions($body, $excludeUserId) {
    $stripped = preg_replace('/\[quote(?:=[^\]]*)?\](?:(?!\[\/quote\]).)*\[\/quote\]/is', '', $body);
    if (!preg_match_all('/@([a-zA-Z0-9_]{3,30})/', $stripped, $matches)) {
        return [];
    }
    $usernames = array_values(array_unique(array_map('strtolower', $matches[1])));
    if (empty($usernames)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $stmt = pw_db()->prepare("SELECT id, username FROM users WHERE LOWER(username) IN ($placeholders)");
    $stmt->execute($usernames);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if ((int)$row['id'] !== (int)$excludeUserId) {
            $result[(int)$row['id']] = true;
        }
    }
    return array_keys($result);
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
