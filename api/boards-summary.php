<?php
require_once __DIR__ . '/helpers.php';

// Public read — powers the forum index (topics/posts/latest-post per board).
// Board metadata remains permission-filtered, but the board counters and most
// recent activity are fetched in three set-based queries instead of four
// queries per visible board.
$currentUser = pw_current_user();
$db = pw_db();
$allBoardRows = $db->query('SELECT * FROM forum_boards ORDER BY sort_order')->fetchAll();
$boardRows = [];
$boardList = [];

foreach ($allBoardRows as $boardRow) {
    if (!pw_can_see_board($currentUser, $boardRow)) {
        continue;
    }
    $boardRows[] = $boardRow;
    $boardList[] = [
        'slug' => $boardRow['slug'],
        'name' => $boardRow['name'],
        'description' => $boardRow['description'],
        'icon_key' => $boardRow['icon_key'],
        'accent_color' => $boardRow['accent_color'],
    ];
}

if (empty($boardRows)) {
    pw_json(['ok' => true, 'board_list' => [], 'boards' => []]);
}

$slugs = array_column($boardRows, 'slug');
$placeholders = implode(',', array_fill(0, count($slugs), '?'));
$topicCounts = [];
$postCounts = [];
$latestByBoard = [];

$stmt = $db->prepare(
    "SELECT board, COUNT(*) AS cnt
     FROM topics
     WHERE board IN ($placeholders) AND is_deleted = 0
     GROUP BY board"
);
$stmt->execute($slugs);
foreach ($stmt->fetchAll() as $row) {
    $topicCounts[$row['board']] = (int)$row['cnt'];
}

$stmt = $db->prepare(
    "SELECT t.board, COUNT(*) AS cnt
     FROM comments c
     JOIN topics t ON t.id = c.topic_id
     WHERE t.board IN ($placeholders) AND t.is_deleted = 0 AND c.is_deleted = 0
     GROUP BY t.board"
);
$stmt->execute($slugs);
foreach ($stmt->fetchAll() as $row) {
    $postCounts[$row['board']] = (int)$row['cnt'];
}

// MariaDB 10.11 supports ROW_NUMBER(), allowing one latest event per board
// without re-scanning topics/comments once per board.
$latestSql =
    "SELECT ranked.board, ranked.topic_id, ranked.title, ranked.created_at,
            u.display_name, u.role, r.color AS role_color
     FROM (
       SELECT events.*,
              ROW_NUMBER() OVER (PARTITION BY board ORDER BY created_at DESC, event_id DESC) AS row_num
       FROM (
         SELECT t.board, t.id AS topic_id, t.title, t.user_id, t.created_at, t.id AS event_id
         FROM topics t
         WHERE t.board IN ($placeholders) AND t.is_deleted = 0
         UNION ALL
         SELECT t.board, t.id AS topic_id, t.title, c.user_id, c.created_at, c.id AS event_id
         FROM comments c
         JOIN topics t ON t.id = c.topic_id
         WHERE t.board IN ($placeholders) AND t.is_deleted = 0 AND c.is_deleted = 0
       ) AS events
     ) AS ranked
     JOIN users u ON u.id = ranked.user_id
     LEFT JOIN roles r ON r.slug = u.role
     WHERE ranked.row_num = 1";
$stmt = $db->prepare($latestSql);
$stmt->execute(array_merge($slugs, $slugs));
foreach ($stmt->fetchAll() as $row) {
    $latestByBoard[$row['board']] = [
        'topic_id' => (int)$row['topic_id'],
        'title' => $row['title'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'role_color' => $row['role_color'] ?: '#c7ccd6',
        'created_at' => $row['created_at'],
    ];
}

// Server-synced unread tracking: only meaningful when logged in. Guests keep
// the client's localStorage-only fallback (see community.html), so 'seen_at'
// is simply omitted from a guest response rather than sent as always-null.
$seenByBoard = [];
if ($currentUser) {
    $stmt = $db->prepare(
        "SELECT board_slug, seen_at FROM forum_board_seen WHERE user_id = ? AND board_slug IN ($placeholders)"
    );
    $stmt->execute(array_merge([(int)$currentUser['id']], $slugs));
    foreach ($stmt->fetchAll() as $row) {
        $seenByBoard[$row['board_slug']] = $row['seen_at'];
    }
}

$out = [];
foreach ($boardRows as $boardRow) {
    $slug = $boardRow['slug'];
    $out[$slug] = [
        'topic_count' => isset($topicCounts[$slug]) ? $topicCounts[$slug] : 0,
        'post_count' => isset($postCounts[$slug]) ? $postCounts[$slug] : 0,
        'latest' => isset($latestByBoard[$slug]) ? $latestByBoard[$slug] : null,
    ];
    if ($currentUser) {
        $out[$slug]['seen_at'] = isset($seenByBoard[$slug]) ? $seenByBoard[$slug] : null;
    }
}

pw_json(['ok' => true, 'board_list' => $boardList, 'boards' => $out]);
