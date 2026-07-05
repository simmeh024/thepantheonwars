<?php
require_once __DIR__ . '/../helpers.php';

$board = isset($_GET['board']) ? trim($_GET['board']) : '';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    pw_error('Unknown board.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT t.id, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role,
            COALESCE(rc.reply_count, 0) AS reply_count,
            COALESCE(rc.last_reply_at, t.created_at) AS last_activity
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN (
       SELECT topic_id, COUNT(*) AS reply_count, MAX(created_at) AS last_reply_at
       FROM comments
       WHERE is_deleted = 0
       GROUP BY topic_id
     ) rc ON rc.topic_id = t.id
     WHERE t.board = ? AND t.is_deleted = 0
     ORDER BY t.is_pinned DESC, last_activity DESC
     LIMIT 200'
);
$stmt->execute([$board]);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'created_at' => $r['created_at'],
        'last_activity' => $r['last_activity'],
        'is_pinned' => (bool)$r['is_pinned'],
        'is_locked' => (bool)$r['is_locked'],
        'user_id' => (int)$r['user_id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'reply_count' => (int)$r['reply_count'],
    ];
}, $rows);

pw_json(['ok' => true, 'topics' => $out]);
