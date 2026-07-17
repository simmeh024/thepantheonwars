<?php
require_once __DIR__ . '/../helpers.php';

$board = isset($_GET['board']) ? trim($_GET['board']) : '';
if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
    pw_error('Unknown board.');
}

// A board that doesn't exist and a board the visitor isn't allowed to see
// return the exact same error -- distinguishing them would leak a hidden
// board's existence to anyone who guesses its slug.
$boardRow = pw_forum_board_by_slug($board);
if (!$boardRow || !pw_can_see_board(pw_current_user(), $boardRow)) {
    pw_error('Unknown board.');
}

$currentUser = pw_current_user();
$db = pw_db();
$stmt = $db->prepare(
    'SELECT t.id, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count,
            COALESCE(MAX(c.created_at), t.created_at) AS last_activity' .
    ($currentUser ? ', fts.seen_at AS seen_at' : '') . "
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     LEFT JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0" .
    ($currentUser ? ' LEFT JOIN forum_topic_seen fts ON fts.topic_id = t.id AND fts.user_id = ?' : '') . '
     WHERE t.board = ? AND t.is_deleted = 0
     GROUP BY t.id, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
              u.display_name, u.role, ro.color' . ($currentUser ? ', fts.seen_at' : '') . '
     ORDER BY t.is_pinned DESC, COALESCE(MAX(c.created_at), t.created_at) DESC
     LIMIT 200'
);
$params = $currentUser ? [(int)$currentUser['id'], $board] : [$board];
$stmt->execute($params);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($currentUser) {
    $row = [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'created_at' => $r['created_at'],
        'last_activity' => $r['last_activity'],
        'is_pinned' => (bool)$r['is_pinned'],
        'is_locked' => (bool)$r['is_locked'],
        'user_id' => (int)$r['user_id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'role_color' => $r['role_color'] ?: '#c7ccd6',
        'reply_count' => (int)$r['reply_count'],
    ];
    if ($currentUser) {
        $row['seen_at'] = $r['seen_at'];
    }
    return $row;
}, $rows);

pw_json(['ok' => true, 'topics' => $out]);
