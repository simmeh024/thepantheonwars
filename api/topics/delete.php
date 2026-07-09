<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT user_id FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$topic = $stmt->fetch();

if (!$topic) {
    pw_error('That topic is already gone.', 404);
}
if (!pw_has_permission($user, 'community.delete_any') && (int)$topic['user_id'] !== (int)$user['id']) {
    pw_error('You can only delete your own topics.', 403);
}

$stmt = $db->prepare('UPDATE topics SET is_deleted = 1 WHERE id = ?');
$stmt->execute([$id]);

// Cascade: replies of a deleted topic shouldn't keep counting toward post
// counts / leaderboards even though the row itself is left intact.
$stmt = $db->prepare('UPDATE comments SET is_deleted = 1 WHERE topic_id = ?');
$stmt->execute([$id]);

pw_json(['ok' => true]);
