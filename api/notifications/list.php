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

// Optional filters driven by the notifications.html filter chips: a
// comma-separated subset of types (unknown values are silently dropped
// rather than erroring, since this only narrows an already-own-rows-only
// query) and an unread-only flag.
$allowedTypes = ['like', 'mention', 'quote', 'report_resolved', 'world_available'];
$types = [];
if (!empty($_GET['types'])) {
    foreach (explode(',', $_GET['types']) as $t) {
        $t = trim($t);
        if (in_array($t, $allowedTypes, true)) {
            $types[] = $t;
        }
    }
}
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';

$whereExtra = '';
$typePlaceholders = [];
foreach ($types as $i => $t) {
    $typePlaceholders[] = ':type' . $i;
}
if ($typePlaceholders) {
    $whereExtra .= ' AND n.type IN (' . implode(',', $typePlaceholders) . ')';
}
if ($unreadOnly) {
    $whereExtra .= ' AND n.is_read = 0';
}

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM notifications n WHERE n.user_id = :user_id' . $whereExtra);
$countStmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
foreach ($types as $i => $t) {
    $countStmt->bindValue(':type' . $i, $t);
}
$countStmt->execute();
$total = (int)$countStmt->fetch()['c'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT n.id, n.type, n.topic_id, n.comment_id, n.report_id, n.world_id, n.excerpt, n.is_read, n.created_at,
            a.id AS actor_id, a.display_name AS actor_display_name, r.color AS actor_role_color,
            t.title AS topic_title,
            w.slug AS world_slug, w.name AS world_name,
            (SELECT COUNT(*) FROM message_likes ml
             WHERE ml.target_type = IF(n.comment_id IS NOT NULL, 'comment', 'topic')
               AND ml.target_id = IFNULL(n.comment_id, n.topic_id)) AS like_count
     FROM notifications n
     LEFT JOIN users a ON a.id = n.actor_user_id
     LEFT JOIN roles r ON r.slug = a.role
     LEFT JOIN topics t ON t.id = n.topic_id
     LEFT JOIN worlds w ON w.id = n.world_id
     WHERE n.user_id = :user_id" . $whereExtra . "
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
foreach ($types as $i => $t) {
    $stmt->bindValue(':type' . $i, $t);
}
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
        'world_id' => $r['world_id'] !== null ? (int)$r['world_id'] : null,
        'world_slug' => $r['world_slug'],
        'world_name' => $r['world_name'],
        'topic_title' => $r['topic_title'],
        'excerpt' => $r['excerpt'],
        'like_count' => (int)$r['like_count'],
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
