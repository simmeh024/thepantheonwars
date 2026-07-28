<?php
/**
 * Manually logs a backup for the System Status "Last Backup" row (see
 * status-helpers.php's pw_check_last_backup() for why this is
 * self-reported rather than an automated check -- cPanel's account
 * backups are disabled on this host).
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../runtime-cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dashboards.view_system_status');

$input = pw_input();
pw_require_csrf($input);

$note = isset($input['note']) ? trim($input['note']) : '';
if (mb_strlen($note) > 255) {
    $note = mb_substr($note, 0, 255);
}

$db = pw_db();
$stmt = $db->prepare('INSERT INTO backup_log (note, logged_by) VALUES (?, ?)');
$stmt->execute([$note !== '' ? $note : null, $adminUser['id']]);

// The backup signal is part of both dashboard snapshots. Clear them so a
// newly logged backup is reflected immediately instead of after their TTL.
pw_admin_runtime_cache_forget($db, 'admin-system-signals-v2');
pw_admin_runtime_cache_forget($db, 'admin-system-status-detail-v3');

pw_log_admin_activity('backup_logged', 'Logged a manual backup.', $adminUser);

pw_json(['ok' => true]);
