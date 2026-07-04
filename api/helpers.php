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

header('Content-Type: application/json; charset=utf-8');

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
function pw_current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = pw_db()->prepare('SELECT id, username, email, display_name, overlord_affinity, is_admin, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function pw_require_login() {
    $user = pw_current_user();
    if (!$user) {
        pw_error('You need to be logged in to do that.', 401);
    }
    return $user;
}
