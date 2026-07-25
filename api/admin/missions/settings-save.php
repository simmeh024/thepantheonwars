<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input();
pw_require_csrf($input);

/* The URL is validated against the same allow-list the public reader applies,
 * so an arbitrary path can never be stored -- this value ends up inside a CSS
 * url() on every player's Missions page. An unrecognised path is rejected
 * outright rather than silently blanked, so a mistyped filename is reported
 * instead of appearing to save and then doing nothing. */
$rawUrl = trim((string)($input['url'] ?? ''));
$url = pw_missions_watermark_url($rawUrl);
if ($rawUrl !== '' && $url === '') {
    pw_error('Choose a watermark from the mission image library, or upload a new one.');
}

$opacity = filter_var($input['opacity'] ?? 8, FILTER_VALIDATE_INT);
if ($opacity === false || $opacity < 1 || $opacity > 40) pw_error('Watermark strength must be between 1% and 40%.');

// Without an image there is nothing to draw, so "on" is not a state that can be
// stored -- the toggle would otherwise read as enabled while showing nothing.
$enabled = !empty($input['enabled']) && $url !== '' ? '1' : '0';

$db = pw_db();
$stmt = $db->prepare('INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
$stmt->execute(['missions_watermark_url', $url]);
$stmt->execute(['missions_watermark_enabled', $enabled]);
$stmt->execute(['missions_watermark_opacity', (string)$opacity]);

pw_log_admin_activity(
    'mission_settings_updated',
    $url === ''
        ? 'Cleared the Missions page watermark.'
        : 'Updated the Missions page watermark (' . ($enabled === '1' ? 'shown' : 'hidden') . ' at ' . $opacity . '% strength).',
    $admin
);
pw_json(['ok' => true, 'watermark' => pw_missions_watermark_settings()]);
