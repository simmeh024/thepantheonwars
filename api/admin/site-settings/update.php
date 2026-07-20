<?php
require_once __DIR__ . '/../../oauth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$adminUser = pw_require_permission('site_settings.manage');
$input = pw_input();
pw_require_csrf($input);

$googleEnabled = !empty($input['google_enabled']);
$appleEnabled = !empty($input['apple_enabled']);
$maintenanceEnabled = !empty($input['maintenance_enabled']);
$maintenanceMessage = trim((string)($input['maintenance_message'] ?? ''));
if (mb_strlen($maintenanceMessage) > 1000) {
    pw_error('Maintenance message must be 1000 characters or fewer.');
}

$values = [
    'oauth_google_enabled' => $googleEnabled ? '1' : '0',
    'oauth_apple_enabled' => $appleEnabled ? '1' : '0',
    'maintenance_mode_enabled' => $maintenanceEnabled ? '1' : '0',
    'maintenance_message' => $maintenanceMessage,
];
$stmt = pw_db()->prepare('INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP');
foreach ($values as $key => $value) {
    $stmt->execute([$key, $value]);
}

pw_log_admin_activity(
    'site_settings_updated',
    'Updated Site Settings -- Google OAuth ' . ($googleEnabled ? 'enabled' : 'disabled') . ', Apple OAuth ' . ($appleEnabled ? 'enabled' : 'disabled')
        . ', Maintenance Mode ' . ($maintenanceEnabled ? 'enabled' : 'disabled') . '.',
    $adminUser
);

pw_json(['ok' => true, 'oauth' => pw_oauth_settings(), 'maintenance' => pw_maintenance_settings_raw()]);
