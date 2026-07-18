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

$existsStmt = $db->prepare('SELECT id FROM topic_subscriptions WHERE topic_id = ? AND user_id = ?');
$existsStmt->execute([$topicId, $user['id']]);
$existing = $existsStmt->fetch();

if ($existing) {
    $db->prepare('DELETE FROM topic_subscriptions WHERE id = ?')->execute([$existing['id']]);
    pw_json(['ok' => true, 'watched' => false]);
}

$db->prepare('INSERT INTO topic_subscriptions (user_id, topic_id) VALUES (?, ?)')->execute([$user['id'], $topicId]);
pw_json(['ok' => true, 'watched' => true]);
