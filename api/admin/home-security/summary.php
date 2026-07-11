<?php
/**
 * Feeds the Home dashboard's "Security Snapshot" card -- failed login
 * attempts, currently locked accounts, and currently banned accounts.
 * A security-monitoring angle distinct from System Status's infra checks
 * and Community Metrics' 24h banned-count (which only counts bans that
 * happened in the last 24h, not the total currently in effect).
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dashboards.view_home');

$db = pw_db();

$failedStmt = $db->query(
    "SELECT COUNT(*) AS c FROM login_attempts WHERE success = 0 AND created_at >= NOW() - INTERVAL 24 HOUR"
);
$failedLogins = (int)$failedStmt->fetch()['c'];

$lockedStmt = $db->query(
    'SELECT COUNT(*) AS c FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()'
);
$lockedAccounts = (int)$lockedStmt->fetch()['c'];

// Matches pw_is_banned(): banned_at set, and either no expiry (permanent)
// or the expiry hasn't passed yet.
$bannedStmt = $db->query(
    "SELECT COUNT(*) AS c FROM users WHERE banned_at IS NOT NULL AND (banned_until IS NULL OR banned_until > NOW())"
);
$bannedAccounts = (int)$bannedStmt->fetch()['c'];

pw_json([
    'ok' => true,
    'failed_logins_24h' => $failedLogins,
    'locked_accounts' => $lockedAccounts,
    'banned_accounts' => $bannedAccounts,
]);
