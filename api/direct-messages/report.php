<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
$category = isset($input['category']) ? trim((string)$input['category']) : 'other';
if ($messageId <= 0 || $reason === '') {
    pw_error('Choose a message and explain the report.');
}
if (mb_strlen($reason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}
if (!in_array($category, ['spam', 'harassment', 'off_topic', 'spoiler_untagged', 'other'], true)) {
    $category = 'other';
}
$db = pw_db();
$stmt = $db->prepare(
    'SELECT dm.id FROM direct_messages dm
     JOIN direct_conversations c ON c.id = dm.conversation_id
     WHERE dm.id = ? AND (c.user_low_id = ? OR c.user_high_id = ?)'
);
$stmt->execute([$messageId, $user['id'], $user['id']]);
if (!$stmt->fetch()) {
    pw_error('That message is not available.', 404);
}
$dup = $db->prepare("SELECT id FROM content_reports WHERE target_type = 'direct_message' AND target_id = ? AND reporter_user_id = ? AND status = 'open'");
$dup->execute([$messageId, $user['id']]);
if ($dup->fetch()) {
    pw_error('You have already reported this message.');
}
$stmt = $db->prepare('INSERT INTO content_reports (target_type, target_id, reporter_user_id, reason, category) VALUES ("direct_message", ?, ?, ?, ?)');
$stmt->execute([$messageId, $user['id'], $reason, $category]);
pw_json(['ok' => true]);
