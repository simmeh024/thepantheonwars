<?php
require_once __DIR__ . '/direct-message-helpers.php';

$user = pw_require_login();
$conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = pw_input();
    pw_require_csrf($input);
    $conversationId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
}
if ($conversationId <= 0) {
    pw_error('Missing conversation id.');
}
$conversation = pw_dm_conversation_for_user($conversationId, (int)$user['id']);
if (!$conversation) {
    pw_error('That conversation is not available.', 404);
}
$db = pw_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isTyping = !empty($input['is_typing']);
    if ($isTyping) {
        $stmt = $db->prepare(
            'INSERT INTO direct_message_typing (conversation_id, user_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$conversationId, (int)$user['id']]);
    } else {
        $stmt = $db->prepare('DELETE FROM direct_message_typing WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$conversationId, (int)$user['id']]);
    }
    pw_json(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pw_error('Method not allowed.', 405);
}
$counterpartId = pw_dm_counterpart_id($conversation, (int)$user['id']);
$stmt = $db->prepare(
    'SELECT 1 FROM direct_message_typing
     WHERE conversation_id = ? AND user_id = ? AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 12 SECOND)'
);
$stmt->execute([$conversationId, $counterpartId]);
pw_json(['ok' => true, 'is_typing' => (bool)$stmt->fetch()]);
