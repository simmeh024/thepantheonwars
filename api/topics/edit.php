<?php
require_once __DIR__ . '/../helpers.php';

// Moderation-only edit: lets a moderator/admin fix a topic's title or body
// (typos, rule violations, redacting something) without deleting the whole
// thread. Regular authors don't get an edit control -- this is a staff tool,
// same scoping as Pin/Lock/Move.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
if (!pw_has_permission($user, 'community.edit_any')) {
    pw_error('Only moderators can edit topics.', 403);
}

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
$stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    pw_error('That topic no longer exists.', 404);
}

$stmt = $db->prepare('UPDATE topics SET title = ?, body = ?, edited_at = ?, edited_by = ? WHERE id = ?');
$stmt->execute([$title, $body, date('Y-m-d H:i:s'), (int)$user['id'], $id]);

pw_log_admin_activity('topic_edited', 'Edited topic #' . $id . ' as moderator.', $user);

pw_json(['ok' => true]);
