<?php
/**
 * Persists which world a member has pointed the header weather widget at.
 *
 * Same trust level as api/presence/update.php and
 * api/newsletter-subscription/update.php: the member's own account, CSRF
 * protected, no permission key involved. Signed-out visitors never reach here
 * -- their choice lives in localStorage.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
pw_require_csrf($input);
$user = pw_require_login();

$slug = trim((string)($input['slug'] ?? ''));

// An empty slug clears the choice, returning the widget to its default world.
if ($slug !== '') {
    if (strlen($slug) > 50) {
        pw_error('Unrecognized world.', 422);
    }
    // Only a world that is actually unlocked and weather-enabled may be stored,
    // so a crafted request cannot pin the widget to a sealed record. This is
    // the same availability gate api/worlds-weather-glance.php applies, checked
    // again here because this is a separate entry point.
    $stmt = pw_db()->prepare(
        'SELECT w.slug FROM worlds w
         JOIN world_weather_profiles p ON p.world_id = w.id
         WHERE w.slug = ? AND w.status = \'available\' AND p.enabled = 1'
    );
    $stmt->execute([$slug]);
    if (!$stmt->fetch()) {
        pw_error('That world is not available.', 422);
    }
}

try {
    pw_db()->prepare('UPDATE users SET weather_world_slug = ? WHERE id = ?')
        ->execute([$slug === '' ? null : $slug, (int)$user['id']]);
} catch (PDOException $e) {
    // sql/migration_weather_widget.sql may not have been run yet. The widget
    // keeps the choice in localStorage regardless, so this is not worth
    // surfacing as an error to the visitor.
    pw_json(['ok' => true, 'slug' => $slug, 'stored' => false]);
}

pw_json(['ok' => true, 'slug' => $slug, 'stored' => true]);
