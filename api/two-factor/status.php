<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../two-factor-helpers.php';

$user = pw_require_login();
$db = pw_db();

$passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$passwordStmt->execute([$user['id']]);
$passwordHash = $passwordStmt->fetchColumn();
$hasPassword = $passwordHash !== false && $passwordHash !== null && $passwordHash !== '';

pw_json([
    'ok' => true,
    'available' => pw_two_factor_table_available($db) && pw_two_factor_crypto_available(),
    'migration_ready' => pw_two_factor_table_available($db),
    'enabled' => $hasPassword && pw_two_factor_is_enabled($db, (int)$user['id']),
    'has_password' => $hasPassword,
    'period_seconds' => PW_TWO_FACTOR_PERIOD,
]);
