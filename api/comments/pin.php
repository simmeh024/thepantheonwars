<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
if (!in_array($user['role'], ['admin', 'moderator'], true)) {
    pw_error('Only the author and moderators can pin messages.', 403);
}

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing comment id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, parent_id, is_pinned FROM comments WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$comment = $stmt->fetch();

if (!$comment) {
    pw_error('That message is already gone.', 404);
}
if ($comment['parent_id'] !== null) {
    pw_error('Only top-level messages can be pinned.');
}

$newState = $comment['is_pinned'] ? 0 : 1;
$stmt = $db->prepare('UPDATE comments SET is_pinned = ?, pinned_at = ? WHERE id = ?');
$stmt->execute([$newState, $newState ? date('Y-m-d H:i:s') : null, $id]);

pw_json(['ok' => true, 'isPinned' => (bool)$newState]);
