<?php
require_once __DIR__ . '/helpers.php';

$user = pw_current_user();

if ($user) {
    // Heartbeat: this endpoint is called on every page load (js/members.js),
    // so it doubles as an "online now" signal for the member list.
    $stmt = pw_db()->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);
}

pw_json([
    'ok' => true,
    'loggedIn' => $user !== null,
    'user' => $user ? [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'overlord_affinity' => $user['overlord_affinity'],
        'role' => $user['role'],
    ] : null,
    'csrf' => pw_csrf_token(),
]);
