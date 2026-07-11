<?php
require_once __DIR__ . '/../helpers.php';

// Moderation-only edit for a single reply -- same staff-tool scoping as
// api/topics/edit.php (see that file's comment for why authors don't get
// their own edit control here).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
if (!pw_has_permission($user, 'community.edit_any')) {
    pw_error('Only moderators can edit messages.', 403);
}

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
if (function_exists('mb_strlen') ? mb_strlen($body) > 2000 : strlen($body) > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    pw_error('That message no longer exists.', 404);
}

$stmt = $db->prepare('UPDATE comments SET body = ?, edited_at = ? WHERE id = ?');
$stmt->execute([$body, date('Y-m-d H:i:s'), $id]);

pw_log_admin_activity('comment_edited', 'Edited reply #' . $id . ' as moderator.', $user);

pw_json(['ok' => true]);
