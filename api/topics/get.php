<?php
require_once __DIR__ . '/../helpers.php';

// Rendering note: title/body/display name below are RAW text, not HTML-escaped.
// The front-end must render them with textContent (never innerHTML) to stay XSS-safe.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT t.id, t.board, t.title, t.body, t.created_at, t.is_pinned, t.is_locked,
            t.edited_at, t.user_id, u.display_name, u.role
     FROM topics t
     JOIN users u ON u.id = t.user_id
     WHERE t.id = ? AND t.is_deleted = 0'
);
$stmt->execute([$id]);
$topic = $stmt->fetch();

if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$postCountStmt = $db->prepare(
    'SELECT
       (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0) +
       (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) AS cnt'
);
$postCountStmt->execute([(int)$topic['user_id'], (int)$topic['user_id']]);
$postCountRow = $postCountStmt->fetch();

$likeCountStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM message_likes WHERE target_type = 'topic' AND target_id = ?");
$likeCountStmt->execute([$id]);
$likeCount = (int)$likeCountStmt->fetch()['cnt'];

$currentUser = pw_current_user();
$currentId = $currentUser ? (int)$currentUser['id'] : null;
$isAdmin = $currentUser ? in_array($currentUser['role'], ['admin', 'moderator'], true) : false;

$likedByMe = false;
if ($currentId !== null) {
    $myLikeStmt = $db->prepare("SELECT id FROM message_likes WHERE target_type = 'topic' AND target_id = ? AND user_id = ?");
    $myLikeStmt->execute([$id, $currentId]);
    $likedByMe = (bool)$myLikeStmt->fetch();
}

pw_json([
    'ok' => true,
    'topic' => [
        'id' => (int)$topic['id'],
        'board' => $topic['board'],
        'title' => $topic['title'],
        'body' => $topic['body'],
        'created_at' => $topic['created_at'],
        'is_pinned' => (bool)$topic['is_pinned'],
        'is_locked' => (bool)$topic['is_locked'],
        'edited_at' => $topic['edited_at'],
        'user_id' => (int)$topic['user_id'],
        'display_name' => $topic['display_name'],
        'role' => $topic['role'],
        'post_count' => (int)$postCountRow['cnt'],
        'canDelete' => $isAdmin || ($currentId !== null && $currentId === (int)$topic['user_id']),
        'canModerate' => $isAdmin,
        'like_count' => $likeCount,
        'likedByMe' => $likedByMe,
    ],
]);
