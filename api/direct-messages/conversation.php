<?php
require_once __DIR__ . '/direct-message-helpers.php';

$user = pw_require_login();
$conversationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($conversationId <= 0) {
    pw_error('Missing conversation id.');
}
$conversation = pw_dm_conversation_for_user($conversationId, (int)$user['id']);
if (!$conversation) {
    pw_error('That conversation is not available.', 404);
}

$beforeId = isset($_GET['before_id']) ? max(0, (int)$_GET['before_id']) : 0;
$sql = 'SELECT dm.id, dm.sender_user_id, dm.body, dm.created_at, u.display_name, u.role, r.color AS role_color
        FROM direct_messages dm
        JOIN users u ON u.id = dm.sender_user_id
        LEFT JOIN roles r ON r.slug = u.role
        WHERE dm.conversation_id = ?';
$params = [$conversationId];
if ($beforeId > 0) {
    $sql .= ' AND dm.id < ?';
    $params[] = $beforeId;
}
$sql .= ' ORDER BY dm.id DESC LIMIT 50';
$stmt = pw_db()->prepare($sql);
$stmt->execute($params);
$rows = array_reverse($stmt->fetchAll());

$counterpartId = pw_dm_counterpart_id($conversation, (int)$user['id']);
$counterpart = pw_dm_public_member($counterpartId);
if (!$counterpart) {
    pw_error('That member is no longer available.', 404);
}

$canSend = !pw_direct_messages_blocked($user, $counterpartId)
    && (pw_is_staff_messenger($user) || !empty($counterpart['messaging_available']));
unset($counterpart['messaging_available']);

pw_json([
    'ok' => true,
    'conversation' => [
        'id' => (int)$conversation['id'],
        'counterpart' => $counterpart,
        'can_send' => $canSend,
    ],
    'messages' => array_map('pw_dm_message_row', $rows),
    'has_older' => count($rows) === 50,
]);
