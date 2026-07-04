<?php
require_once __DIR__ . '/helpers.php';

$user = pw_current_user();

pw_json([
    'ok' => true,
    'loggedIn' => $user !== null,
    'user' => $user ? [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'overlord_affinity' => $user['overlord_affinity'],
        'is_admin' => (bool)$user['is_admin'],
    ] : null,
    'csrf' => pw_csrf_token(),
]);
