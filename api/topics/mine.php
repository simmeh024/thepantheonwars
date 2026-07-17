<?php
/**
 * My Topics tab: topics the current user started, across every board --
 * no board-visibility re-check, same as api/topics/get.php doesn't re-gate
 * a topic's author against their own board access changing later.
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

$boardRows = $db->query('SELECT slug, name FROM forum_boards')->fetchAll();
$boardNames = [];
foreach ($boardRows as $b) {
    $boardNames[$b['slug']] = $b['name'];
}

$stmt = $db->prepare(
    "SELECT t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count,
            COALESCE(MAX(c.created_at), t.created_at) AS last_activity,
            (SELECT id FROM topic_bookmarks WHERE topic_id = t.id AND user_id = ?) AS bookmark_id,
            fts.seen_at AS seen_at
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     LEFT JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
     LEFT JOIN forum_topic_seen fts ON fts.topic_id = t.id AND fts.user_id = ?
     WHERE t.user_id = ? AND t.is_deleted = 0
     GROUP BY t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
              u.display_name, u.role, ro.color, fts.seen_at
     ORDER BY t.created_at DESC
     LIMIT 100"
);
$stmt->execute([$user['id'], $user['id'], $user['id']]);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($boardNames) {
    return [
        'id' => (int)$r['id'],
        'board' => $r['board'],
        'board_name' => isset($boardNames[$r['board']]) ? $boardNames[$r['board']] : $r['board'],
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
        'bookmarked' => !empty($r['bookmark_id']),
        'seen_at' => $r['seen_at'],
    ];
}, $rows);

pw_json(['ok' => true, 'topics' => $out]);
