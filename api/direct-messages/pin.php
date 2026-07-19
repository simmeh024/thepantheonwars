<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$conversationId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
$isPinned = !empty($input['is_pinned']) ? 1 : 0;
if ($conversationId <= 0) {
    pw_error('Missing conversation id.');
}
if (!pw_dm_conversation_for_user($conversationId, (int)$user['id'])) {
    pw_error('That conversation is not available.', 404);
}

$stmt = pw_db()->prepare(
    'INSERT INTO direct_conversation_preferences (user_id, conversation_id, is_pinned)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE is_pinned = VALUES(is_pinned), updated_at = CURRENT_TIMESTAMP'
);
$stmt->execute([(int)$user['id'], $conversationId, $isPinned]);

pw_json(['ok' => true, 'is_pinned' => (bool)$isPinned]);
