<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$topicId = isset($input['topic_id']) ? (int)$input['topic_id'] : 0;
if ($topicId <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, is_locked FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$topicId]);
$topicRow = $stmt->fetch();
if (!$topicRow) {
    pw_error('That topic no longer exists.', 404);
}
if ((int)$topicRow['is_locked'] === 1) {
    pw_error('This topic is locked. A moderator must unlock it before new replies can be posted.', 403);
}

// Note: anyone logged in may reply inside an existing topic, including in
// Announcements -- only *starting* a new Announcements topic is staff-only
// (enforced in api/topics/create.php).

$body = isset($input['body']) ? trim($input['body']) : '';
if ($body === '') {
    pw_error('Your message is empty.');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 2000 : strlen($body) > 2000) {
    pw_error('That message is too long (2000 characters max).');
}

$parentId = null;
$depth = 0;
if (!empty($input['parent_id'])) {
    $parentId = (int)$input['parent_id'];
    $stmt = $db->prepare('SELECT id, depth FROM comments WHERE id = ? AND topic_id = ? AND is_deleted = 0');
    $stmt->execute([$parentId, $topicId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        pw_error('The message you are replying to no longer exists.');
    }
    $depth = (int)$parent['depth'] + 1;
    if ($depth > 2) {
        pw_error('Replies can only go two levels deep.');
    }
}

$stmt = $db->prepare('INSERT INTO comments (user_id, topic_id, parent_id, depth, body) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$user['id'], $topicId, $parentId, $depth, $body]);

pw_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
