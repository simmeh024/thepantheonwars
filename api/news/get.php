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
    'SELECT n.id, n.slug, n.title, n.body, n.header_image_url, n.author_type, n.comments_enabled, n.published_at,
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

// If this article was published from a Dispatch Composer draft, surface the
// Dispatches that draft attached as source material in a public sidecard.
// Most news_posts rows have no matching composer draft (imported/legacy
// articles, or ones created directly in News Management) -- those simply
// get an empty list.
$attachedDispatches = [];
$composerStmt = $db->prepare('SELECT id FROM dispatch_composer_posts WHERE news_post_id = ?');
$composerStmt->execute([(int)$post['id']]);
$composerRow = $composerStmt->fetch();
if ($composerRow) {
    $attachedDispatches = pw_composer_attached_dispatches($db, (int)$composerRow['id']);
}

pw_json([
    'ok' => true,
    'entry' => [
        'id' => (int)$post['id'],
        'slug' => $post['slug'],
        'title' => $post['title'],
        'body' => $body,
        'body_is_rich' => pw_news_is_rich_body($body),
        'header_image_url' => $post['header_image_url'],
        'author_type' => $post['author_type'],
        'author_display_name' => $post['author_display_name'],
        'comments_enabled' => (bool)$post['comments_enabled'],
        'comment_count' => $count,
        'published_at' => $post['published_at'],
        'tags' => $tagStmt->fetchAll(),
        'attached_dispatches' => $attachedDispatches,
    ],
]);
