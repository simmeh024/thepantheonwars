<?php
/** Public, read-only detail record for one News transmission. */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin/news/news-helpers.php';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    pw_error('Unknown news article.', 404);
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT n.id, n.slug, n.title, n.body, n.author_type, n.comments_enabled, n.published_at,
            u.display_name AS author_display_name
     FROM news_posts n
     LEFT JOIN users u ON u.id = n.author_user_id
     WHERE n.slug = ?'
);
$stmt->execute([$slug]);
$post = $stmt->fetch();
if (!$post) {
    pw_error('That news article no longer exists.', 404);
}
$body = pw_news_public_body($post['body']);

$tagStmt = $db->prepare(
    'SELECT t.slug, t.label
     FROM news_post_tags npt
     INNER JOIN news_tags t ON t.id = npt.news_tag_id
     WHERE npt.news_post_id = ?
     ORDER BY t.label ASC'
);
$tagStmt->execute([(int)$post['id']]);

$countStmt = $db->prepare('SELECT COUNT(*) AS count FROM news_comments WHERE news_post_id = ?');
$countStmt->execute([(int)$post['id']]);
$count = (int)$countStmt->fetch()['count'];

pw_json([
    'ok' => true,
    'entry' => [
        'id' => (int)$post['id'],
        'slug' => $post['slug'],
        'title' => $post['title'],
        'body' => $body,
        'body_is_rich' => pw_news_is_rich_body($body),
        'author_type' => $post['author_type'],
        'author_display_name' => $post['author_display_name'],
        'comments_enabled' => (bool)$post['comments_enabled'],
        'comment_count' => $count,
        'published_at' => $post['published_at'],
        'tags' => $tagStmt->fetchAll(),
    ],
]);
