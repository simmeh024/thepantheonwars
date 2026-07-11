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
    pw_error('Missing comment id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT user_id FROM comments WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$comment = $stmt->fetch();

if (!$comment) {
    pw_error('That message is already gone.', 404);
}
if (!pw_has_permission($user, 'community.delete_any') && (int)$comment['user_id'] !== (int)$user['id']) {
    pw_error('You can only delete your own messages.', 403);
}

$stmt = $db->prepare('UPDATE comments SET is_deleted = 1 WHERE id = ?');
$stmt->execute([$id]);

// Only log when acting as a moderator on someone else's reply -- an author
// deleting their own post is ordinary self-service, not an admin action.
if ((int)$comment['user_id'] !== (int)$user['id']) {
    pw_log_admin_activity('comment_deleted', 'Deleted another member\'s reply #' . $id . ' as moderator.', $user);
}

pw_json(['ok' => true]);
