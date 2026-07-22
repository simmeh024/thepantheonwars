<?php
/**
 * Own-rows-only aggregate counts backing notifications.html's status card:
 * total unread and a per-type breakdown, independent of whatever page/
 * filter is currently applied to list.php so the stat pills stay accurate
 * even when the list itself is filtered down to one type.
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

$stmt = $db->prepare(
    'SELECT type, COUNT(*) AS cnt, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_cnt
     FROM notifications WHERE user_id = ? GROUP BY type'
);
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

$counts = ['like' => 0, 'mention' => 0, 'quote' => 0, 'report_resolved' => 0, 'world_available' => 0, 'news_published' => 0, 'topic_reply' => 0, 'icon_unlocked' => 0, 'direct_message' => 0, 'new_device_login' => 0, 'warning_issued' => 0, 'weather_alert' => 0];
$total = 0;
$unread = 0;
foreach ($rows as $r) {
    if (isset($counts[$r['type']])) {
        $counts[$r['type']] = (int)$r['cnt'];
    }
    $total += (int)$r['cnt'];
    $unread += (int)$r['unread_cnt'];
}

pw_json([
    'ok' => true,
    'total' => $total,
    'unread' => $unread,
    'counts' => $counts,
]);
