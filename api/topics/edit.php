<?php
require_once __DIR__ . '/../helpers.php';

// Moderators/admins (community.edit_any) can edit any topic at any time --
// staff tool, same scoping as Pin/Lock/Move. An author who is not a
// moderator may also edit their own topic, but only within
// PW_SELF_EDIT_WINDOW_SECONDS of posting it (a typo-fix window, not an
// open-ended rewrite tool -- ownership and the timestamp are always
// re-checked server-side against the stored row, never trusted from the
// client).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

define('PW_SELF_EDIT_WINDOW_SECONDS', 30 * 60);

$user = pw_require_login();

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing topic id.');
}

$title = isset($input['title']) ? trim($input['title']) : '';
$body = isset($input['body']) ? trim($input['body']) : '';

$titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
$bodyLen = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);

if ($title === '') {
    pw_error('A topic needs a title.');
}
if ($titleLen > 200) {
    pw_error('That title is too long (200 characters max).');
}
if ($body === '') {
    pw_error('A topic needs a message.');
}
if ($bodyLen > 3500) {
    pw_error('That message is too long (3500 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, user_id, created_at FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$topic = $stmt->fetch();
if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$isModerator = pw_has_permission($user, 'community.edit_any');
$isOwnerWithinWindow = (int)$topic['user_id'] === (int)$user['id']
    && (time() - strtotime($topic['created_at'])) <= PW_SELF_EDIT_WINDOW_SECONDS;
if (!$isModerator && !$isOwnerWithinWindow) {
    pw_error('You can only edit your own topic within 30 minutes of posting it.', 403);
}

$stmt = $db->prepare('UPDATE topics SET title = ?, body = ?, edited_at = ?, edited_by = ? WHERE id = ?');
$stmt->execute([$title, $body, date('Y-m-d H:i:s'), (int)$user['id'], $id]);

pw_log_admin_activity(
    'topic_edited',
    'Edited topic #' . $id . ($isModerator ? ' as moderator.' : ' (self-edit).'),
    $user
);

pw_json(['ok' => true]);
