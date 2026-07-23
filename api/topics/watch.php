<?php
require_once __DIR__ . '/../helpers.php';

// "Watch this thread" toggle -- distinct from topic_bookmarks (a manual
// save with no notification behaviour). Watching actually notifies on
// every new reply (see api/comments/post.php). Any logged-in member may
// watch/unwatch any topic they can see; a topic's own creator and anyone
// who replies to it are auto-subscribed elsewhere, this endpoint only
// handles the explicit toggle.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$topicId = isset($input['topic_id']) ? (int)$input['topic_id'] : 0;
if ($topicId <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$topicId]);
if (!$stmt->fetch()) {
    pw_error('That topic no longer exists.', 404);
}

$mode = isset($input['mode']) ? trim((string)$input['mode']) : '';
$validModes = ['instant', 'daily', 'mentions'];
if ($mode !== '' && !in_array($mode, $validModes, true)) {
    pw_error('Unknown subscription setting.');
}

$existsStmt = $db->prepare('SELECT id, delivery_mode FROM topic_subscriptions WHERE topic_id = ? AND user_id = ?');
$existsStmt->execute([$topicId, $user['id']]);
$existing = $existsStmt->fetch();

if ($mode !== '') {
    $db->prepare(
        'INSERT INTO topic_subscriptions (user_id, topic_id, delivery_mode) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE delivery_mode = VALUES(delivery_mode)'
    )->execute([$user['id'], $topicId, $mode]);
    pw_json(['ok' => true, 'watched' => true, 'delivery_mode' => $mode]);
}

if ($existing) {
    $db->prepare('DELETE FROM topic_subscriptions WHERE id = ?')->execute([$existing['id']]);
    pw_json(['ok' => true, 'watched' => false, 'delivery_mode' => null]);
}

$db->prepare('INSERT INTO topic_subscriptions (user_id, topic_id) VALUES (?, ?)')->execute([$user['id'], $topicId]);
pw_json(['ok' => true, 'watched' => true, 'delivery_mode' => 'instant']);
