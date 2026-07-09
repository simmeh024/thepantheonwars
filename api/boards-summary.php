<?php
require_once __DIR__ . '/helpers.php';

// Public read — powers the forum index (topics/posts/latest-post per board).
$boards = ['announcements', 'assembly', 'offworld'];
$db = pw_db();
$out = [];

foreach ($boards as $board) {
    $topicCountStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM topics WHERE board = ? AND is_deleted = 0');
    $topicCountStmt->execute([$board]);
    $topicCount = (int)$topicCountStmt->fetch()['cnt'];

    $postCountStmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM comments c JOIN topics t ON t.id = c.topic_id
         WHERE t.board = ? AND c.is_deleted = 0 AND t.is_deleted = 0'
    );
    $postCountStmt->execute([$board]);
    $postCount = (int)$postCountStmt->fetch()['cnt'];

    $latestStmt = $db->prepare(
        '(SELECT t.id AS topic_id, t.title, t.user_id, t.created_at
          FROM topics t WHERE t.board = ? AND t.is_deleted = 0)
         UNION ALL
         (SELECT t.id AS topic_id, t.title, c.user_id, c.created_at
          FROM comments c JOIN topics t ON t.id = c.topic_id
          WHERE t.board = ? AND c.is_deleted = 0 AND t.is_deleted = 0)
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $latestStmt->execute([$board, $board]);
    $latest = $latestStmt->fetch();

    $latestOut = null;
    if ($latest) {
        $userStmt = $db->prepare('SELECT u.display_name, u.role, r.color AS role_color FROM users u LEFT JOIN roles r ON r.slug = u.role WHERE u.id = ?');
        $userStmt->execute([(int)$latest['user_id']]);
        $u = $userStmt->fetch();
        $latestOut = [
            'topic_id' => (int)$latest['topic_id'],
            'title' => $latest['title'],
            'display_name' => $u ? $u['display_name'] : 'Unknown',
            'role' => $u ? $u['role'] : 'member',
            'role_color' => $u && $u['role_color'] ? $u['role_color'] : '#c7ccd6',
            'created_at' => $latest['created_at'],
        ];
    }

    $out[$board] = [
        'topic_count' => $topicCount,
        'post_count' => $postCount,
        'latest' => $latestOut,
    ];
}

pw_json(['ok' => true, 'boards' => $out]);
