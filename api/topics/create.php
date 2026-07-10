<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$board = isset($input['board']) ? trim($input['board']) : '';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    pw_error('Unknown board.');
}

if ($board === 'announcements' && !pw_has_permission($user, 'community.post_announcements')) {
    pw_error('Only the author and moderators can start new topics in Announcements.', 403);
}

$title = isset($input['title']) ? trim($input['title']) : '';
$body = isset($input['body']) ? trim($input['body']) : '';

$titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
$bodyLen = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);

if ($title === '') {
    pw_error('Give your topic a title.');
}
if ($titleLen > 200) {
    pw_error('That title is too long (200 characters max).');
}
if ($body === '') {
    pw_error('Your message is empty.');
}
if ($bodyLen > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('INSERT INTO topics (board, user_id, title, body) VALUES (?, ?, ?, ?)');
$stmt->execute([$board, $user['id'], $title, $body]);
$topicId = (int)$db->lastInsertId();

foreach (pw_extract_mentions($body, $user['id']) as $mentionedUserId) {
    pw_notify($mentionedUserId, 'mention', $user['id'], $topicId, null, null, $title);
}

pw_json(['ok' => true, 'id' => $topicId]);
