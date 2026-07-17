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

// "Trending" is a topic that picked up several replies recently, not just a
// topic with a high all-time reply_count -- an old thread with 50 replies
// from a year ago shouldn't outrank a small thread getting real activity
// right now. recent_reply_count is a plain aggregate expression (like
// COUNT(c.id) already is), so it needs no GROUP BY entry of its own.
$sql =
    "SELECT t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            COUNT(c.id) AS reply_count,
            SUM(CASE WHEN c.created_at >= UTC_TIMESTAMP() - INTERVAL 6 HOUR THEN 1 ELSE 0 END) AS recent_reply_count,
            COALESCE(MAX(c.created_at), t.created_at) AS last_activity" .
    ($currentUser ? ', (SELECT id FROM topic_bookmarks WHERE topic_id = t.id AND user_id = ?) AS bookmark_id, fts.seen_at AS seen_at' : '') . "
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     LEFT JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0" .
    ($currentUser ? ' LEFT JOIN forum_topic_seen fts ON fts.topic_id = t.id AND fts.user_id = ?' : '') . "
     WHERE t.board IN ($placeholders) AND t.is_deleted = 0
     GROUP BY t.id, t.board, t.title, t.created_at, t.is_pinned, t.is_locked, t.user_id,
              u.display_name, u.role, ro.color" . ($currentUser ? ', fts.seen_at' : '') . "
     ORDER BY COALESCE(MAX(c.created_at), t.created_at) DESC
     LIMIT 100";

$params = $currentUser ? [(int)$currentUser['id'], (int)$currentUser['id']] : [];
$params = array_merge($params, $visibleSlugs);

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($boardNames, $currentUser) {
    $row = [
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
        // Fixed threshold, not exposed in any admin UI -- see the comment
        // above the query for why this looks at recent velocity rather
        // than the plain all-time reply_count already returned above.
        'is_trending' => (int)$r['recent_reply_count'] >= 3,
        'bookmarked' => !empty($r['bookmark_id']),
    ];
    if ($currentUser) {
        $row['seen_at'] = $r['seen_at'];
    }
    return $row;
}, $rows);

pw_json(['ok' => true, 'topics' => $out]);
