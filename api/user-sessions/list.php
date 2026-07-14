<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$token = pw_current_session_token();

try {
    $stmt = pw_db()->prepare(
        'SELECT id, session_token_hash, device_label, browser_name, operating_system, ip_address, country_code, country_name, auth_provider, created_at, last_active_at, expires_at
         FROM user_sessions
         WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
         ORDER BY last_active_at DESC, created_at DESC'
    );
    $stmt->execute([$user['id']]);
    $currentHash = $token ? pw_session_hash($token) : '';
    $sessions = array_map(function ($row) use ($currentHash) {
        return [
            'id' => (int)$row['id'],
            'device_label' => $row['device_label'] ?: 'Unknown device',
            'browser_name' => $row['browser_name'] ?: 'Unknown browser',
            'operating_system' => $row['operating_system'] ?: 'Unknown operating system',
            'location' => $row['country_name'] ?: 'Location unavailable',
            'country_code' => $row['country_code'],
            'ip_masked' => pw_mask_ip($row['ip_address']),
            'auth_provider' => $row['auth_provider'] ?: 'password',
            'created_at' => $row['created_at'],
            'last_active_at' => $row['last_active_at'],
            'expires_at' => $row['expires_at'],
            'is_current' => $currentHash !== '' && hash_equals($currentHash, $row['session_token_hash']),
        ];
    }, $stmt->fetchAll());
    pw_json(['ok' => true, 'sessions' => $sessions]);
} catch (Throwable $e) {
    pw_error('Session management is not available yet. Please try again after the security update has been completed.', 503);
}
