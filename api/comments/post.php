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

$body = isset($input['body']) ? trim($input['body']) : '';
if ($body === '') {
    pw_error('Your message is empty.');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 2000 : strlen($body) > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$parentId = null;
if (!empty($input['parent_id'])) {
    $parentId = (int)$input['parent_id'];
    $db = pw_db();
    $stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND board = ? AND is_deleted = 0');
    $stmt->execute([$parentId, $board]);
    if (!$stmt->fetch()) {
        pw_error('The message you are replying to no longer exists.');
    }
} else {
    $db = pw_db();
}

$stmt = $db->prepare('INSERT INTO comments (user_id, board, parent_id, body) VALUES (?, ?, ?, ?)');
$stmt->execute([$user['id'], $board, $parentId, $body]);

pw_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
