<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_mod_or_admin();

$db = pw_db();

$status = isset($_GET['status']) ? trim($_GET['status']) : 'open';
if (!in_array($status, ['open', 'resolved', 'all'], true)) {
    $status = 'open';
}
$statusSql = $status === 'all' ? '' : 'AND cr.status = :status';

function pw_fetch_reports($db, $targetType, $statusSql, $status) {
    if ($targetType === 'topic') {
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       t.id AS topic_id, t.title AS topic_title, t.board AS board, t.is_locked AS is_locked, t.is_deleted AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN topics t ON t.id = cr.target_id
                JOIN users author ON author.id = t.user_id
                WHERE cr.target_type = 'topic' $statusSql";
    } else {
        $sql = "SELECT cr.id, cr.target_type, cr.target_id, cr.reason, cr.status, cr.resolution,
                       cr.resolved_at, cr.created_at,
                       reporter.id AS reporter_id, reporter.username AS reporter_username, reporter.display_name AS reporter_display_name,
                       resolver.username AS resolver_username,
                       pt.id AS topic_id, pt.title AS topic_title, pt.board AS board, pt.is_locked AS is_locked, c.is_deleted AS target_deleted,
                       author.id AS author_id, author.username AS author_username, author.display_name AS author_display_name
                FROM content_reports cr
                JOIN users reporter ON reporter.id = cr.reporter_user_id
                LEFT JOIN users resolver ON resolver.id = cr.resolved_by
                JOIN comments c ON c.id = cr.target_id
                JOIN topics pt ON pt.id = c.topic_id
                JOIN users author ON author.id = c.user_id
                WHERE cr.target_type = 'comment' $statusSql";
    }
    $stmt = $db->prepare($sql);
    if ($statusSql !== '') {
        $stmt->execute([':status' => $status]);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

$rows = array_merge(
    pw_fetch_reports($db, 'topic', $statusSql, $status),
    pw_fetch_reports($db, 'comment', $statusSql, $status)
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
    ];
}, $rows);

pw_json(['ok' => true, 'reports' => $out]);
