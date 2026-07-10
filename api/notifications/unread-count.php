<?php
/**
 * Lightweight unread count for the nav bell badge. Polled periodically by
 * js/notifications.js (visibility-gated, see that file for the interval).
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();

$stmt = pw_db()->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
$stmt->execute([$user['id']]);
$count = (int)$stmt->fetch()['c'];

pw_json(['ok' => true, 'unread' => $count]);
