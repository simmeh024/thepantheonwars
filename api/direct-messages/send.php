<?php
require_once __DIR__ . '/direct-message-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$recipientId = isset($input['recipient_id']) ? (int)$input['recipient_id'] : 0;
$body = isset($input['body']) ? trim((string)$input['body']) : '';
if ($recipientId <= 0 || $recipientId === (int)$user['id']) {
    pw_error('Choose another member to message.');
}
if ($body === '') {
    pw_error('Your message is empty.');
}
if (mb_strlen($body) > 2000) {
    pw_error('Messages can be up to 2,000 characters.');
}

$db = pw_db();
$recipientStmt = $db->prepare('SELECT id, role, banned_at, banned_until FROM users WHERE id = ?');
$recipientStmt->execute([$recipientId]);
$recipient = $recipientStmt->fetch();
// Staff may leave an essential message for a suspended account as well; it
// becomes visible if that member returns. Ordinary members can only contact
// active accounts.
if (!$recipient || (!pw_is_staff_messenger($user) && pw_is_banned($recipient))) {
    pw_error('That member is not available for messaging.', 404);
}
// A muted sender may still reach staff (to ask about the mute); everyone
// else is blocked the same way a muted member is blocked from posting.
if (pw_is_muted($user) && !pw_is_staff_messenger($recipient)) {
    pw_require_not_muted($user);
}
if (pw_direct_messages_blocked($user, $recipientId)) {
    pw_error('This conversation is not available.');
}

// Small, server-side anti-flood limit. It is separate from the general forum
// limits because private messages are not public content.
$rateStmt = $db->prepare('SELECT COUNT(*) AS c FROM direct_messages WHERE sender_user_id = ? AND created_at >= NOW() - INTERVAL 1 MINUTE');
$rateStmt->execute([$user['id']]);
if ((int)$rateStmt->fetch()['c'] >= 15) {
    pw_error('Please wait a moment before sending more messages.', 429);
}

$lowId = min((int)$user['id'], $recipientId);
$highId = max((int)$user['id'], $recipientId);
try {
    $db->beginTransaction();
    $create = $db->prepare(
        'INSERT INTO direct_conversations (user_low_id, user_high_id, created_by)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id'
    );
    $create->execute([$lowId, $highId, $user['id']]);
    $conversationStmt = $db->prepare('SELECT id FROM direct_conversations WHERE user_low_id = ? AND user_high_id = ? FOR UPDATE');
    $conversationStmt->execute([$lowId, $highId]);
    $conversation = $conversationStmt->fetch();
    if (!$conversation) {
        throw new RuntimeException('Conversation could not be created.');
    }
    $conversationId = (int)$conversation['id'];
    $messageStmt = $db->prepare('INSERT INTO direct_messages (conversation_id, sender_user_id, body) VALUES (?, ?, ?)');
    $messageStmt->execute([$conversationId, $user['id'], $body]);
    $messageId = (int)$db->lastInsertId();
    $update = $db->prepare('UPDATE direct_conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?');
    $update->execute([$messageId, $conversationId]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    pw_error('Your message could not be sent. Please try again.', 503);
}

// A completed private message must not be reported as failed merely because
// the bell/audit side effects are unavailable during a deploy window.
try {
    pw_notify_direct_message($recipientId, (int)$user['id'], $conversationId, $messageId, preg_replace('/\s+/', ' ', $body));
    if (pw_is_staff_messenger($user)) {
        pw_log_admin_activity('staff_direct_message_sent', 'Sent a private staff message to member #' . $recipientId . '.', $user);
    }
} catch (Throwable $e) {
    // Best effort: the message itself committed above and remains available.
}

pw_json(['ok' => true, 'conversation_id' => $conversationId, 'message_id' => $messageId]);
