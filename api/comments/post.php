<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$board = isset($input['board']) ? trim($input['board']) : 'community';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    $board = 'community';
}

if ($board === 'announcements' && !in_array($user['role'], ['admin', 'moderator'], true)) {
    pw_error('Only the author and moderators can post in Announcements.', 403);
}

$body = isset($input['body']) ? trim($input['body']) : '';
if ($body === '') {
    pw_error('Your message is empty.');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 2000 : strlen($body) > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$parentId = null;
$depth = 0;
$db = pw_db();
if (!empty($input['parent_id'])) {
    $parentId = (int)$input['parent_id'];
    $stmt = $db->prepare('SELECT id, depth FROM comments WHERE id = ? AND board = ? AND is_deleted = 0');
    $stmt->execute([$parentId, $board]);
    $parent = $stmt->fetch();
    if (!$parent) {
        pw_error('The message you are replying to no longer exists.');
    }
    $depth = (int)$parent['depth'] + 1;
    if ($depth > 2) {
        pw_error('Replies can only go two levels deep.');
    }
}

$stmt = $db->prepare('INSERT INTO comments (user_id, board, parent_id, depth, body) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$user['id'], $board, $parentId, $depth, $body]);

pw_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
