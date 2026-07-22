<?php
require_once __DIR__ . '/oauth.php';

$user = pw_current_user();
$roleColor = '#c7ccd6';
$weatherWorldSlug = null;

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
    $dailyReturnAwarded = pw_award_daily_return(pw_db(), (int)$user['id']);
    if ($dailyReturnAwarded > 0) {
        $user['reputation'] = (int)$user['reputation'] + $dailyReturnAwarded;
    }

    $stmt = pw_db()->prepare('SELECT color FROM roles WHERE slug = ?');
    $stmt->execute([$user['role']]);
    $roleRow = $stmt->fetch();
    if ($roleRow) {
        $roleColor = $roleRow['color'];
    }

    // Which world the header weather widget is pointed at. Read here, in the
    // signed-in branch of an endpoint every page already calls, so the widget
    // costs no request of its own to learn the member's choice.
    //
    // Deliberately NOT folded into pw_current_user()'s SELECT: that runs on
    // every authenticated request site-wide, so a deploy landing before
    // sql/migration_weather_widget.sql would fatal the whole site rather than
    // just this one value (the mistake newsletter_subscribed had to guard
    // against). Scoped and guarded here, a missing column costs nothing.
    try {
        $stmt = pw_db()->prepare('SELECT weather_world_slug FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $weatherRow = $stmt->fetch();
        if ($weatherRow && $weatherRow['weather_world_slug'] !== null && $weatherRow['weather_world_slug'] !== '') {
            $weatherWorldSlug = (string)$weatherRow['weather_world_slug'];
        }
    } catch (PDOException $e) {
        // Migration pending; the widget falls back to its default world.
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
        'presence_status' => $user['presence_status'] ?? 'online',
        'reputation' => pw_reputation_info((int)($user['reputation'] ?? 0)),
        'selected_icon' => $user['selected_icon'] ?? null,
        'is_staff_messenger' => pw_is_staff_messenger($user),
        'weather_world_slug' => $weatherWorldSlug,
    ] : null,
    // Frontend uses this (not the raw role string) to decide what to show --
    // '*' means every permission (superuser role, e.g. admin).
    'permissions' => $user ? pw_user_permissions($user) : [],
    'csrf' => pw_csrf_token(),
    // Public and safe: just which sign-in providers are currently switched on
    // in Site Settings, so the login modal can hide a disabled provider's
    // button without a separate request.
    'oauth' => pw_oauth_settings(),
    'maintenance' => pw_maintenance_settings(),
]);
