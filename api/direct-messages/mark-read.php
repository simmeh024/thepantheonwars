<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$conversationId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
if ($conversationId <= 0) {
    pw_error('Missing conversation id.');
}
$conversation = pw_dm_conversation_for_user($conversationId, (int)$user['id']);
if (!$conversation) {
    pw_error('That conversation is not available.', 404);
}

$lastId = (int)($conversation['last_message_id'] ?? 0);
$column = (int)$conversation['user_low_id'] === (int)$user['id']
    ? 'user_low_last_read_message_id'
    : 'user_high_last_read_message_id';
$stmt = pw_db()->prepare("UPDATE direct_conversations SET $column = GREATEST($column, ?) WHERE id = ?");
$stmt->execute([$lastId, $conversationId]);
$stmt = pw_db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = "direct_message" AND conversation_id = ?');
$stmt->execute([$user['id'], $conversationId]);

pw_json(['ok' => true]);
