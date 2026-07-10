<?php
/**
 * Paginated list of the logged-in user's own notifications -- backs both
 * the nav bell dropdown (small per_page) and the full notifications.html
 * page (larger per_page, grouped by date client-side).
 */
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage <= 0) {
    $perPage = 20;
}
if ($perPage > 100) {
    $perPage = 100;
}

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$total = (int)$countStmt->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT n.id, n.type, n.topic_id, n.comment_id, n.report_id, n.excerpt, n.is_read, n.created_at,
            a.id AS actor_id, a.display_name AS actor_display_name, r.color AS actor_role_color,
            t.title AS topic_title
     FROM notifications n
     LEFT JOIN users a ON a.id = n.actor_user_id
     LEFT JOIN roles r ON r.slug = a.role
     LEFT JOIN topics t ON t.id = n.topic_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'type' => $r['type'],
        'actor' => $r['actor_id'] ? [
            'id' => (int)$r['actor_id'],
            'display_name' => $r['actor_display_name'],
            'role_color' => $r['actor_role_color'] ?: '#c7ccd6',
        ] : null,
        'topic_id' => $r['topic_id'] !== null ? (int)$r['topic_id'] : null,
        'comment_id' => $r['comment_id'] !== null ? (int)$r['comment_id'] : null,
        'report_id' => $r['report_id'] !== null ? (int)$r['report_id'] : null,
        'topic_title' => $r['topic_title'],
        'excerpt' => $r['excerpt'],
        'is_read' => (bool)$r['is_read'],
        'created_at' => $r['created_at'],
    ];
}, $rows);

pw_json([
    'ok' => true,
    'entries' => $out,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
]);
