<?php
/**
 * Bookmarks tab: topics the current user has bookmarked, most recently
 * bookmarked first. Still filtered through pw_can_see_board() per row --
 * a board can go private after something in it was bookmarked.
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

$boardRows = $db->query('SELECT * FROM forum_boards')->fetchAll();
$boardsBySlug = [];
foreach ($boardRows as $b) {
    $boardsBySlug[$b['slug']] = $b;
}

$stmt = $db->prepare(
    "SELECT t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count,
            COALESCE(MAX(c.created_at), t.created_at) AS last_activity,
            tb.created_at AS bookmarked_at
     FROM topic_bookmarks tb
     JOIN topics t ON t.id = tb.topic_id AND t.is_deleted = 0
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     LEFT JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
     WHERE tb.user_id = ?
     GROUP BY tb.id, tb.created_at, t.id, t.board, t.title, t.created_at, t.is_pinned,
              t.is_locked, t.user_id, u.display_name, u.role, ro.color
     ORDER BY tb.created_at DESC
     LIMIT 100"
);
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $boardRow = isset($boardsBySlug[$r['board']]) ? $boardsBySlug[$r['board']] : null;
    if (!$boardRow || !pw_can_see_board($user, $boardRow)) {
        continue;
    }
    $out[] = [
        'id' => (int)$r['id'],
        'board' => $r['board'],
        'board_name' => $boardRow['name'],
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
        'bookmarked' => true,
    ];
}

pw_json(['ok' => true, 'topics' => $out]);
