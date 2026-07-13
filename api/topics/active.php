<?php
/**
 * Active Topics tab: every topic across every board the current visitor
 * can see, sorted by last activity -- no pin priority here (pins are a
 * per-board concept, not global). Public, no login required; the
 * `bookmarked` flag is only meaningful when logged in.
 */
require_once __DIR__ . '/../helpers.php';

$db = pw_db();
$currentUser = pw_current_user();

$boardRows = $db->query('SELECT * FROM forum_boards')->fetchAll();
$visibleSlugs = [];
$boardNames = [];
foreach ($boardRows as $b) {
    if (pw_can_see_board($currentUser, $b)) {
        $visibleSlugs[] = $b['slug'];
        $boardNames[$b['slug']] = $b['name'];
    }
}

if (empty($visibleSlugs)) {
    pw_json(['ok' => true, 'topics' => []]);
}

$placeholders = implode(',', array_fill(0, count($visibleSlugs), '?'));

$sql =
    "SELECT t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count,
            COALESCE(MAX(c.created_at), t.created_at) AS last_activity" .
    ($currentUser ? ', (SELECT id FROM topic_bookmarks WHERE topic_id = t.id AND user_id = ?) AS bookmark_id' : '') . "
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     LEFT JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
     WHERE t.board IN ($placeholders) AND t.is_deleted = 0
     GROUP BY t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
              u.display_name, u.role, ro.color
     ORDER BY COALESCE(MAX(c.created_at), t.created_at) DESC
     LIMIT 100";

$params = $currentUser ? [(int)$currentUser['id']] : [];
$params = array_merge($params, $visibleSlugs);

$stmt = $db->prepare($sql);
$stmt->execute($params);
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
    ];
}, $rows);

pw_json(['ok' => true, 'topics' => $out]);
