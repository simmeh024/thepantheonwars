<?php
/**
 * Public, unauthenticated page-view beacon fired by js/main.js on every
 * public page load (never on admin/index.html, since that page doesn't
 * load main.js). Fire-and-forget from the client -- no CSRF, no session
 * requirement, and the response body is never read by the caller.
 *
 * Deliberately does not use pw_require_login()/pw_require_csrf(): a visit
 * beacon needs to work for anonymous, logged-out visitors, which is most
 * of the traffic this is meant to measure.
 */
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();

$path = isset($input['path']) ? (string)$input['path'] : '';
$path = substr($path, 0, 255);
if ($path === '') {
    pw_error('Missing path.', 400);
}

$visitorId = isset($input['visitor_id']) ? (string)$input['visitor_id'] : '';
if (!preg_match('/^[a-f0-9-]{36}$/i', $visitorId)) {
    pw_error('Invalid visitor id.', 400);
}

// Never trust a client-parsed hostname -- derive it ourselves from the raw
// referrer URL, if any.
$referrerHost = null;
if (!empty($input['referrer'])) {
    $referrerHost = parse_url((string)$input['referrer'], PHP_URL_HOST);
    if ($referrerHost) {
        $referrerHost = substr($referrerHost, 0, 255);
    }
}

$user = pw_current_user();
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

$stmt = pw_db()->prepare(
    'INSERT INTO page_views (path, referrer_host, visitor_id, user_id, ip_address, user_agent)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $path,
    $referrerHost,
    $visitorId,
    $user ? (int)$user['id'] : null,
    pw_client_ip(),
    $userAgent,
]);

pw_json(['ok' => true]);
