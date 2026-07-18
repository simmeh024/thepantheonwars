<?php
require_once __DIR__ . '/../helpers.php';

// Moderators/admins (community.edit_any) can edit any reply at any time --
// same staff-tool scoping as api/topics/edit.php. An author who is not a
// moderator may also edit their own reply within
// PW_SELF_EDIT_WINDOW_SECONDS of posting it -- see that file's comment for
// the full reasoning; kept identical here.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

if (!defined('PW_SELF_EDIT_WINDOW_SECONDS')) {
    define('PW_SELF_EDIT_WINDOW_SECONDS', 30 * 60);
}

$user = pw_require_login();

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing comment id.');
}

$body = isset($input['body']) ? trim($input['body']) : '';
if ($body === '') {
    pw_error('Your message is empty.');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 3500 : strlen($body) > 3500) {
    pw_error('That message is too long (3500 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, user_id, created_at FROM comments WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$comment = $stmt->fetch();
if (!$comment) {
    pw_error('That message no longer exists.', 404);
}

$isModerator = pw_has_permission($user, 'community.edit_any');
$isOwnerWithinWindow = (int)$comment['user_id'] === (int)$user['id']
    && (time() - strtotime($comment['created_at'])) <= PW_SELF_EDIT_WINDOW_SECONDS;
if (!$isModerator && !$isOwnerWithinWindow) {
    pw_error('You can only edit your own reply within 30 minutes of posting it.', 403);
}

$stmt = $db->prepare('UPDATE comments SET body = ?, edited_at = ?, edited_by = ? WHERE id = ?');
$stmt->execute([$body, date('Y-m-d H:i:s'), (int)$user['id'], $id]);

pw_log_admin_activity(
    'comment_edited',
    'Edited reply #' . $id . ($isModerator ? ' as moderator.' : ' (self-edit).'),
    $user
);

pw_json(['ok' => true]);
