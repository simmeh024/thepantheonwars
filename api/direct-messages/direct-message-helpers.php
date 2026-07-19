<?php
require_once __DIR__ . '/../helpers.php';

function pw_dm_conversation_for_user($conversationId, $userId) {
    $stmt = pw_db()->prepare(
        'SELECT id, user_low_id, user_high_id, last_message_id, last_message_at,
                user_low_last_read_message_id, user_high_last_read_message_id
         FROM direct_conversations
         WHERE id = ? AND (user_low_id = ? OR user_high_id = ?)'
    );
    $stmt->execute([$conversationId, $userId, $userId]);
    return $stmt->fetch();
}

function pw_dm_counterpart_id($conversation, $userId) {
    return (int)$conversation['user_low_id'] === (int)$userId
        ? (int)$conversation['user_high_id']
        : (int)$conversation['user_low_id'];
}

function pw_dm_public_member($userId) {
    $stmt = pw_db()->prepare(
        'SELECT u.id, u.display_name, u.role, u.banned_at, u.banned_until, u.presence_status, u.last_active_at, u.created_at, r.color AS role_color
         FROM users u LEFT JOIN roles r ON r.slug = u.role WHERE u.id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'role_color' => $row['role_color'] ?: '#c7ccd6',
        'presence_status' => pw_public_presence_status($row['presence_status'], $row['last_active_at']),
        'created_at' => $row['created_at'],
        'messaging_available' => !pw_is_banned($row),
    ];
}

function pw_dm_message_row($row) {
    return [
        'id' => (int)$row['id'],
        'sender_id' => (int)$row['sender_user_id'],
        'body' => $row['body'],
        'created_at' => $row['created_at'],
        'sender' => [
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'role_color' => $row['role_color'] ?: '#c7ccd6',
        ],
    ];
}
