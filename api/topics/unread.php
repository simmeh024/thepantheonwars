<?php
/**
 * Cross-board unread view for signed-in members. A topic appears only when
 * it has at least one reply newer than the member's per-topic seen marker.
 * Board visibility is checked before the query so restricted forums never
 * leak into another member's unread list.
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();
$boardRows = $db->query('SELECT * FROM forum_boards')->fetchAll();
$visibleSlugs = [];
$boardNames = [];
foreach ($boardRows as $board) {
    if (pw_can_see_board($user, $board)) {
        $visibleSlugs[] = $board['slug'];
        $boardNames[$board['slug']] = $board['name'];
    }
}
if (!$visibleSlugs) {
    pw_json(['ok' => true, 'topics' => []]);
}

$placeholders = implode(',', array_fill(0, count($visibleSlugs), '?'));
$stmt = $db->prepare(
    "SELECT t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count, MAX(c.created_at) AS last_activity,
            fts.seen_at
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
     LEFT JOIN forum_topic_seen fts ON fts.topic_id = t.id AND fts.user_id = ?
     WHERE t.board IN ($placeholders) AND t.is_deleted = 0
     GROUP BY t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
              u.display_name, u.role, ro.color, fts.seen_at
     HAVING fts.seen_at IS NULL OR MAX(c.created_at) > fts.seen_at
     ORDER BY MAX(c.created_at) DESC
     LIMIT 100"
);
$stmt->execute(array_merge([(int)$user['id']], $visibleSlugs));

$topics = array_map(function ($row) use ($boardNames) {
    return [
        'id' => (int)$row['id'],
        'board' => $row['board'],
        'board_name' => $boardNames[$row['board']] ?? $row['board'],
        'title' => $row['title'],
        'created_at' => $row['created_at'],
        'last_activity' => $row['last_activity'],
        'is_pinned' => (bool)$row['is_pinned'],
        'is_locked' => (bool)$row['is_locked'],
        'user_id' => (int)$row['user_id'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'role_color' => $row['role_color'] ?: '#c7ccd6',
        'reply_count' => (int)$row['reply_count'],
        'seen_at' => $row['seen_at'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'topics' => $topics]);
