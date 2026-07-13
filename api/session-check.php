<?php
require_once __DIR__ . '/helpers.php';

$user = pw_current_user();
$roleColor = '#c7ccd6';

if ($user) {
    // Heartbeat: this endpoint runs on page load and every two minutes in an
    // active tab. Only write once per minute: the member list treats users as
    // online for five minutes, so this preserves accuracy while avoiding
    // redundant row locks when a member has several tabs open.
    $stmt = pw_db()->prepare(
        'UPDATE users
         SET last_active_at = NOW()
         WHERE id = ?
           AND (last_active_at IS NULL OR last_active_at < NOW() - INTERVAL 60 SECOND)'
    );
    $stmt->execute([$user['id']]);

    $stmt = pw_db()->prepare('SELECT color FROM roles WHERE slug = ?');
    $stmt->execute([$user['role']]);
    $roleRow = $stmt->fetch();
    if ($roleRow) {
        $roleColor = $roleRow['color'];
    }
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
        'role_color' => $roleColor,
    ] : null,
    // Frontend uses this (not the raw role string) to decide what to show --
    // '*' means every permission (superuser role, e.g. admin).
    'permissions' => $user ? pw_user_permissions($user) : [],
    'csrf' => pw_csrf_token(),
]);
