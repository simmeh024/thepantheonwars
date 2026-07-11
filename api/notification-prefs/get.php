<?php
/**
 * Returns the logged-in user's notification type preferences, backing
 * profile.html's Notification Settings tab. A missing row (the common
 * case -- most users never touch this tab) means every type defaults to
 * enabled, matching pw_notifications_enabled()'s own default in
 * api/helpers.php.
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

$stmt = $db->prepare('SELECT notif_like, notif_mention, notif_quote, notif_report_resolved, notif_world_available FROM notification_preferences WHERE user_id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

$prefs = [
    'like' => $row ? (bool)$row['notif_like'] : true,
    'mention' => $row ? (bool)$row['notif_mention'] : true,
    'quote' => $row ? (bool)$row['notif_quote'] : true,
    'report_resolved' => $row ? (bool)$row['notif_report_resolved'] : true,
    'world_available' => $row ? (bool)$row['notif_world_available'] : true,
];

pw_json(['ok' => true, 'prefs' => $prefs]);
