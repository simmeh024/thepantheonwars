<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
if ($targetId <= 0) {
    pw_error('Missing member id.');
}
$stmt = pw_db()->prepare('DELETE FROM user_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?');
$stmt->execute([$user['id'], $targetId]);
pw_json(['ok' => true]);
