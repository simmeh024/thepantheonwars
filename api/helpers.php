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
// (columns notif_like/notif_mention/notif_quote/notif_report_resolved).
// A missing row (the common case -- most users never touch Notification
// Settings) means "everything enabled", so this only ever needs to read,
// never backfill, on account creation.
function pw_notifications_enabled($userId, $type) {
    $columns = ['like' => 'notif_like', 'mention' => 'notif_mention', 'quote' => 'notif_quote', 'report_resolved' => 'notif_report_resolved', 'world_available' => 'notif_world_available'];
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
function pw_notify($userId, $type, $actorUserId = null, $topicId = null, $commentId = null, $reportId = null, $excerpt = null, $worldId = null) {
    if ($actorUserId !== null && (int)$actorUserId === (int)$userId) {
        return;
    }
    if (!pw_notifications_enabled($userId, $type)) {
        return;
    }
    $stmt = pw_db()->prepare(
        'INSERT INTO notifications (user_id, type, actor_user_id, topic_id, comment_id, report_id, world_id, excerpt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $type,
        $actorUserId,
        $topicId,
        $commentId,
        $reportId,
        $worldId,
        $excerpt !== null ? substr($excerpt, 0, 200) : null,
    ]);
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
