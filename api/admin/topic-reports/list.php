<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('topic_reports.view');

$db = pw_db();

$status = isset($_GET['status']) ? trim($_GET['status']) : 'open';
if (!in_array($status, ['open', 'resolved', 'all'], true)) {
    $status = 'open';
}
$statusSql = $status === 'all' ? '' : 'AND cr.status = :status';

$category = isset($_GET['category']) ? trim($_GET['category']) : '';
if (!in_array($category, ['spam', 'harassment', 'off_topic', 'spoiler_untagged', 'other'], true)) {
    $category = '';
}
$categorySql = $category !== '' ? 'AND cr.category = :category' : '';

function pw_fetch_reports($db, $targetType, $statusSql, $status, $categorySql, $category) {
    if ($targetType === 'topic') {
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.category, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       t.id AS topic_id, t.title AS topic_title, t.board AS board, t.is_locked AS is_locked, t.is_deleted AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name,
                       'forum' AS source, NULL AS news_slug
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN topics t ON t.id = cr.target_id
                JOIN users author ON author.id = t.user_id
                WHERE cr.target_type = 'topic' $statusSql $categorySql";
    } elseif ($targetType === 'comment') {
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.category, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       pt.id AS topic_id, pt.title AS topic_title, pt.board AS board, pt.is_locked AS is_locked, c.is_deleted AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name,
                       'forum' AS source, NULL AS news_slug
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN comments c ON c.id = cr.target_id
                JOIN topics pt ON pt.id = c.topic_id
                JOIN users author ON author.id = c.user_id
                WHERE cr.target_type = 'comment' $statusSql $categorySql";
    } elseif ($targetType === 'news_comment') {
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.category, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       NULL AS topic_id, np.title AS topic_title, NULL AS board, 0 AS is_locked, 0 AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name,
                       'news' AS source, np.slug AS news_slug
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN news_comments nc ON nc.id = cr.target_id
                JOIN news_posts np ON np.id = nc.news_post_id
                JOIN users author ON author.id = nc.user_id
                WHERE cr.target_type = 'news_comment' $statusSql $categorySql";
    } else {
        // Private messages are deliberately visible to staff only when a
        // participant reports a specific row. There is no general inbox or
        // conversation browsing query for moderators.
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.category, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       NULL AS topic_id, 'Private message' AS topic_title, NULL AS board, 0 AS is_locked, 0 AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name,
                       'direct' AS source, NULL AS news_slug, dm.body AS message_body
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN direct_messages dm ON dm.id = cr.target_id
                JOIN users author ON author.id = dm.sender_user_id
                WHERE cr.target_type = 'direct_message' $statusSql $categorySql";
    }
    $stmt = $db->prepare($sql);
    $params = [];
    if ($statusSql !== '') {
        $params[':status'] = $status;
    }
    if ($categorySql !== '') {
        $params[':category'] = $category;
    }
    if ($params) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

$rows = array_merge(
    pw_fetch_reports($db, 'topic', $statusSql, $status, $categorySql, $category),
    pw_fetch_reports($db, 'comment', $statusSql, $status, $categorySql, $category),
    pw_fetch_reports($db, 'news_comment', $statusSql, $status, $categorySql, $category),
    pw_fetch_reports($db, 'direct_message', $statusSql, $status, $categorySql, $category)
);

usort($rows, function ($a, $b) {
    return strcmp($b['created_at'], $a['created_at']);
});

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'target_type' => $r['target_type'],
        'target_id' => (int)$r['target_id'],
        'reason' => $r['reason'],
        'category' => $r['category'],
        'status' => $r['status'],
        'resolution' => $r['resolution'],
        'resolved_at' => $r['resolved_at'],
        'resolver_username' => $r['resolver_username'],
        'created_at' => $r['created_at'],
        'reporter' => [
            'id' => (int)$r['reporter_id'],
            'username' => $r['reporter_username'],
            'display_name' => $r['reporter_display_name'],
        ],
        'author' => [
            'id' => (int)$r['author_id'],
            'username' => $r['author_username'],
            'display_name' => $r['author_display_name'],
        ],
        'topic_id' => (int)$r['topic_id'],
        'topic_title' => $r['topic_title'],
        'board' => $r['board'],
        'is_locked' => (bool)$r['is_locked'],
        'target_deleted' => (bool)$r['target_deleted'],
        'source' => $r['source'],
        'news_slug' => $r['news_slug'],
        'message_body' => isset($r['message_body']) ? $r['message_body'] : null,
    ];
}, $rows);

pw_json(['ok' => true, 'reports' => $out]);
