<?php
/**
 * Lightweight unread direct-message total for the signed-in account menu.
 * It deliberately avoids loading conversation/member details so it can be
 * refreshed with the navigation session heartbeat at negligible cost.
 */
require_once __DIR__ . '/direct-message-helpers.php';

$user = pw_require_login();
$userId = (int)$user['id'];

$stmt = pw_db()->prepare(
    'SELECT COUNT(*) AS c
     FROM direct_messages dm
     JOIN direct_conversations c ON c.id = dm.conversation_id
     WHERE dm.sender_user_id != ?
       AND (c.user_low_id = ? OR c.user_high_id = ?)
       AND dm.id > CASE
           WHEN c.user_low_id = ? THEN c.user_low_last_read_message_id
           ELSE c.user_high_last_read_message_id
       END'
);
$stmt->execute([$userId, $userId, $userId, $userId]);

pw_json(['ok' => true, 'unread' => (int)$stmt->fetch()['c']]);
