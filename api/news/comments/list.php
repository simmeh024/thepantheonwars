<?php
/** Public read endpoint for the flat discussion under one News transmission. */
require_once __DIR__ . '/../../helpers.php';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    pw_error('Unknown news article.', 404);
}

$db = pw_db();
$postStmt = $db->prepare('SELECT id, comments_enabled FROM news_posts WHERE slug = ?');
$postStmt->execute([$slug]);
$post = $postStmt->fetch();
if (!$post) {
    pw_error('That news article no longer exists.', 404);
}
if (!(bool)$post['comments_enabled']) {
    pw_json(['ok' => true, 'comments_enabled' => false, 'comments' => []]);
}

$stmt = $db->prepare(
    'SELECT c.id, c.body, c.created_at, c.user_id,
            u.username, u.display_name, u.role, u.presence_status, u.last_active_at,
            r.color AS role_color
     FROM news_comments c
     INNER JOIN users u ON u.id = c.user_id
     LEFT JOIN roles r ON r.slug = u.role
     WHERE c.news_post_id = ?
     ORDER BY c.created_at ASC, c.id ASC
     LIMIT 500'
);
$stmt->execute([(int)$post['id']]);

$comments = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'body' => $row['body'],
        'created_at' => $row['created_at'],
        'user_id' => (int)$row['user_id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'role_color' => $row['role_color'] ?: '#c7ccd6',
        'presence_status' => pw_public_presence_status($row['presence_status'], $row['last_active_at']),
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'comments_enabled' => true, 'comments' => $comments]);
