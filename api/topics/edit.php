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
if (!in_array($user['role'], ['admin', 'moderator'], true)) {
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
if ($bodyLen > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    pw_error('That topic no longer exists.', 404);
}

$stmt = $db->prepare('UPDATE topics SET title = ?, body = ?, edited_at = ? WHERE id = ?');
$stmt->execute([$title, $body, date('Y-m-d H:i:s'), $id]);

pw_json(['ok' => true]);
