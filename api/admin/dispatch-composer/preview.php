<?php
/**
 * Renders a Composer draft's current saved state in the exact response
 * shape api/news/get.php returns, so news-post.html's existing rendering
 * function is the one and only renderer for both a live article and this
 * preview -- no second, composer-specific public template is created.
 * This never creates a real news post; it only reads what's already stored.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../news/news-helpers.php';

pw_require_permission('dispatch_composer.view');
$db = pw_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing Composer post id.');
}

$stmt = $db->prepare(
    'SELECT cp.title, cp.body, cp.featured_image_url, cp.updated_at, u.display_name AS author_display_name
     FROM dispatch_composer_posts cp
     LEFT JOIN users u ON u.id = cp.updated_by
     WHERE cp.id = ?'
);
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    pw_error('That Composer draft no longer exists.', 404);
}

$body = pw_news_public_body((string)$post['body']);

pw_json([
    'ok' => true,
    'entry' => [
        'id' => $id,
        'slug' => null,
        'title' => $post['title'] !== '' ? $post['title'] : '(untitled draft)',
        'body' => $body,
        'body_is_rich' => pw_news_is_rich_body($body),
        'header_image_url' => $post['featured_image_url'],
        'author_type' => 'member',
        'author_display_name' => $post['author_display_name'],
        'comments_enabled' => false,
        'comment_count' => 0,
        'published_at' => $post['updated_at'],
        'tags' => [],
        'is_preview' => true,
    ],
]);
