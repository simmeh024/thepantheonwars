<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
if ($targetId <= 0 || $targetId === (int)$user['id']) {
    pw_error('Choose another member to block.');
}
$stmt = pw_db()->prepare('SELECT id FROM users WHERE id = ?');
$stmt->execute([$targetId]);
if (!$stmt->fetch()) {
    pw_error('That member no longer exists.', 404);
}
$stmt = pw_db()->prepare('INSERT IGNORE INTO user_blocks (blocker_user_id, blocked_user_id) VALUES (?, ?)');
$stmt->execute([$user['id'], $targetId]);
pw_json(['ok' => true]);
