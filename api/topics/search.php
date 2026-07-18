<?php
/**
 * Forum-wide search across topic titles/bodies and reply bodies, using the
 * FULLTEXT indexes added in migration_forum_enhancements.sql. Public, no
 * login required, but every row is filtered through pw_can_see_board() same
 * as api/topics/active.php -- a hidden board's content must never surface
 * in a search result for a visitor who can't otherwise see that board.
 *
 * A topic can match two ways: directly (its own title/body), or through one
 * of its replies. Each topic appears at most once in the results -- a direct
 * topic match always wins over a reply match, and among reply matches only
 * the single highest-scoring reply per topic is kept, so a popular thread
 * doesn't crowd out everything else.
 */
require_once __DIR__ . '/../helpers.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (function_exists('mb_strlen') ? mb_strlen($q) < 2 : strlen($q) < 2) {
    pw_error('Enter at least 2 characters to search.');
}
if (function_exists('mb_strlen') ? mb_strlen($q) > 100 : strlen($q) > 100) {
    pw_error('Search text is too long (100 characters max).');
}

$currentUser = pw_current_user();
$db = pw_db();

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
    pw_json(['ok' => true, 'results' => []]);
}

// Optional filters from the search UI. board is narrowed against the
// already-computed visible-board list (a hidden board can't be searched
// into via this param any more than via the base query itself); author is
// matched by exact username (the same identity search results already
// display, i.e. the topic's author -- both the topic-match and reply-match
// queries below join the topic's author, not the individual reply's, so
// this stays consistent with what's actually shown); the date range applies
// to the topic's created_at, again matching the only timestamp either
// query already returns.
$boardFilter = isset($_GET['board']) ? trim($_GET['board']) : '';
if ($boardFilter !== '' && !in_array($boardFilter, $visibleSlugs, true)) {
    $boardFilter = '';
}
$authorFilter = isset($_GET['author']) ? trim($_GET['author']) : '';
if (mb_strlen($authorFilter) > 100) {
    $authorFilter = mb_substr($authorFilter, 0, 100);
}
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$extraWhere = '';
$extraParams = [];
if ($boardFilter !== '') {
    $extraWhere .= ' AND t.board = ?';
    $extraParams[] = $boardFilter;
}
if ($authorFilter !== '') {
    $extraWhere .= ' AND u.username = ?';
    $extraParams[] = $authorFilter;
}
if ($dateFrom !== '') {
    $extraWhere .= ' AND t.created_at >= ?';
    $extraParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $extraWhere .= ' AND t.created_at <= ?';
    $extraParams[] = $dateTo . ' 23:59:59';
}

$placeholders = implode(',', array_fill(0, count($visibleSlugs), '?'));

function pw_search_excerpt($text, $q) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $pos = mb_stripos($text, $q);
    if ($pos === false) {
        return mb_substr($text, 0, 160) . (mb_strlen($text) > 160 ? '…' : '');
    }
    $start = max(0, $pos - 60);
    $excerpt = mb_substr($text, $start, 200);
    return ($start > 0 ? '…' : '') . $excerpt . (mb_strlen($text) > $start + 200 ? '…' : '');
}

$topicStmt = $db->prepare(
    "SELECT t.id, t.board, t.title, t.body, t.created_at, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            MATCH(t.title, t.body) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     WHERE t.board IN ($placeholders) AND t.is_deleted = 0
       AND MATCH(t.title, t.body) AGAINST (? IN NATURAL LANGUAGE MODE)
       $extraWhere
     ORDER BY score DESC
     LIMIT 50"
);
$topicStmt->execute(array_merge([$q], $visibleSlugs, [$q], $extraParams));
$topicRows = $topicStmt->fetchAll();

$commentStmt = $db->prepare(
    "SELECT t.id, t.board, t.title, t.created_at, t.user_id,
            u.display_name, u.role, ro.color AS role_color,
            c.id AS comment_id, c.body AS comment_body,
            MATCH(c.body) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
     FROM comments c
     JOIN topics t ON t.id = c.topic_id AND t.is_deleted = 0
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles ro ON ro.slug = u.role
     WHERE t.board IN ($placeholders) AND c.is_deleted = 0
       AND MATCH(c.body) AGAINST (? IN NATURAL LANGUAGE MODE)
       $extraWhere
     ORDER BY score DESC
     LIMIT 50"
);
$commentStmt->execute(array_merge([$q], $visibleSlugs, [$q], $extraParams));
$commentRows = $commentStmt->fetchAll();

$results = [];
foreach ($topicRows as $r) {
    $tid = (int)$r['id'];
    $results[$tid] = [
        'id' => $tid,
        'board' => $r['board'],
        'board_name' => isset($boardNames[$r['board']]) ? $boardNames[$r['board']] : $r['board'],
        'title' => $r['title'],
        'created_at' => $r['created_at'],
        'user_id' => (int)$r['user_id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'role_color' => $r['role_color'] ?: '#c7ccd6',
        // A direct topic match is boosted above an equivalent reply match,
        // so a thread whose own title/body matches always ranks first.
        'score' => (float)$r['score'] + 5,
        'matched_in' => 'topic',
        'excerpt' => pw_search_excerpt($r['body'], $q),
    ];
}
foreach ($commentRows as $r) {
    $tid = (int)$r['id'];
    if (isset($results[$tid])) {
        continue;
    }
    $results[$tid] = [
        'id' => $tid,
        'board' => $r['board'],
        'board_name' => isset($boardNames[$r['board']]) ? $boardNames[$r['board']] : $r['board'],
        'title' => $r['title'],
        'created_at' => $r['created_at'],
        'user_id' => (int)$r['user_id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'role_color' => $r['role_color'] ?: '#c7ccd6',
        'score' => (float)$r['score'],
        'matched_in' => 'reply',
        'comment_id' => (int)$r['comment_id'],
        'excerpt' => pw_search_excerpt($r['comment_body'], $q),
    ];
}

usort($results, function ($a, $b) {
    return $b['score'] <=> $a['score'];
});
$results = array_slice(array_values($results), 0, 50);

pw_json(['ok' => true, 'results' => $results]);
