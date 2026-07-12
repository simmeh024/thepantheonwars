<?php
/**
 * Topic bookmark toggle -- open to any logged-in member (like message
 * likes, not gated behind the admin permission system). Mirrors
 * api/messages/like.php's toggle shape. No notification: this is a private
 * save-for-later action, not a social one.
 */
require_once __DIR__ . '/../helpers.php';

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

$stmt = $db->prepare('SELECT id, board FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$topicId]);
$topic = $stmt->fetch();
if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$boardRow = pw_forum_board_by_slug($topic['board']);
if (!$boardRow || !pw_can_see_board($user, $boardRow)) {
    pw_error('That topic no longer exists.', 404);
}

$stmt = $db->prepare('SELECT id FROM topic_bookmarks WHERE user_id = ? AND topic_id = ?');
$stmt->execute([$user['id'], $topicId]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $db->prepare('DELETE FROM topic_bookmarks WHERE id = ?');
    $stmt->execute([$existing['id']]);
    $bookmarked = false;
} else {
    $stmt = $db->prepare('INSERT INTO topic_bookmarks (user_id, topic_id) VALUES (?, ?)');
    $stmt->execute([$user['id'], $topicId]);
    $bookmarked = true;
}

pw_json(['ok' => true, 'bookmarked' => $bookmarked]);
