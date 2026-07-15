<?php
/** Public, read-only News feed. Deliberately does not require a session. */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin/news/news-helpers.php';

$db = pw_db();
$rows = $db->query(
    'SELECT n.id, n.slug, n.title, n.body, n.author_type, n.published_at, u.display_name AS author_display_name,
            COALESCE(comment_counts.comment_count, 0) AS comment_count
     FROM news_posts n
     LEFT JOIN users u ON u.id = n.author_user_id
     LEFT JOIN (
       SELECT news_post_id, COUNT(*) AS comment_count
       FROM news_comments
       GROUP BY news_post_id
     ) comment_counts ON comment_counts.news_post_id = n.id
     ORDER BY n.published_at DESC, n.id DESC'
)->fetchAll();

$tagRows = $db->query(
    'SELECT npt.news_post_id, t.slug, t.label
     FROM news_post_tags npt
     INNER JOIN news_tags t ON t.id = npt.news_tag_id
     ORDER BY t.label ASC'
)->fetchAll();
$tagsByPost = [];
foreach ($tagRows as $tag) {
    $tagsByPost[(int)$tag['news_post_id']][] = ['slug' => $tag['slug'], 'label' => $tag['label']];
}

$entries = array_map(function ($row) use ($tagsByPost) {
    $body = pw_news_public_body($row['body']);
    return [
        'slug' => $row['slug'],
        'title' => $row['title'],
        'body' => $body,
        'body_is_rich' => pw_news_is_rich_body($body),
        'author_type' => $row['author_type'],
        'author_display_name' => $row['author_display_name'],
        'published_at' => $row['published_at'],
        'comment_count' => (int)$row['comment_count'],
        'tags' => $tagsByPost[(int)$row['id']] ?? [],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $entries]);
