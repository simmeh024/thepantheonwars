<?php
require_once __DIR__ . '/direct-message-helpers.php';

$user = pw_require_login();
$db = pw_db();

$stmt = $db->prepare(
    'SELECT c.id, c.last_message_at,
            other.id AS other_id, other.display_name AS other_display_name, other.role AS other_role,
            role.color AS other_role_color,
            lm.body AS last_body, lm.created_at AS last_created_at, lm.sender_user_id AS last_sender_id,
            (SELECT COUNT(*) FROM direct_messages unread
             WHERE unread.conversation_id = c.id AND unread.sender_user_id != ?
               AND unread.id > CASE WHEN c.user_low_id = ? THEN c.user_low_last_read_message_id ELSE c.user_high_last_read_message_id END) AS unread_count,
            EXISTS(SELECT 1 FROM user_blocks ub WHERE ub.blocker_user_id = ? AND ub.blocked_user_id = other.id) AS blocked_by_me
     FROM direct_conversations c
     JOIN users other ON other.id = CASE WHEN c.user_low_id = ? THEN c.user_high_id ELSE c.user_low_id END
     LEFT JOIN roles role ON role.slug = other.role
     LEFT JOIN direct_messages lm ON lm.id = c.last_message_id
     WHERE c.user_low_id = ? OR c.user_high_id = ?
     ORDER BY c.last_message_at DESC, c.id DESC'
);
$stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);

$conversations = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'counterpart' => [
            'id' => (int)$row['other_id'],
            'display_name' => $row['other_display_name'],
            'role' => $row['other_role'],
            'role_color' => $row['other_role_color'] ?: '#c7ccd6',
        ],
        'last_message' => $row['last_body'] === null ? null : [
            'body' => $row['last_body'],
            'created_at' => $row['last_created_at'],
            'sender_id' => (int)$row['last_sender_id'],
        ],
        'unread_count' => (int)$row['unread_count'],
        'blocked_by_me' => (bool)$row['blocked_by_me'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'conversations' => $conversations]);
