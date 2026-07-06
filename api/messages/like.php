<?php
require_once __DIR__ . '/../helpers.php';

// Polymorphic like toggle -- works on both topics and comments.
// Unlike moderation actions, this is open to any logged-in user.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$targetType = isset($input['target_type']) ? trim($input['target_type']) : '';
$targetId = isset($input['target_id']) ? (int)$input['target_id'] : 0;

if (!in_array($targetType, ['topic', 'comment'], true)) {
    pw_error('Unknown target type.');
}
if ($targetId <= 0) {
    pw_error('Missing target id.');
}

$db = pw_db();

if ($targetType === 'topic') {
    $stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
} else {
    $stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND is_deleted = 0');
}
$stmt->execute([$targetId]);
if (!$stmt->fetch()) {
    pw_error('That message no longer exists.', 404);
}

$stmt = $db->prepare('SELECT id FROM message_likes WHERE target_type = ? AND target_id = ? AND user_id = ?');
$stmt->execute([$targetType, $targetId, $user['id']]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $db->prepare('DELETE FROM message_likes WHERE id = ?');
    $stmt->execute([$existing['id']]);
    $liked = false;
} else {
    $stmt = $db->prepare('INSERT INTO message_likes (target_type, target_id, user_id) VALUES (?, ?, ?)');
    $stmt->execute([$targetType, $targetId, $user['id']]);
    $liked = true;
}

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM message_likes WHERE target_type = ? AND target_id = ?');
$countStmt->execute([$targetType, $targetId]);
$count = (int)$countStmt->fetch()['cnt'];

pw_json(['ok' => true, 'liked' => $liked, 'likeCount' => $count]);
