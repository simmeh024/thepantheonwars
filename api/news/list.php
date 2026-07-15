<?php
/** Public, read-only News feed. Deliberately does not require a session. */
require_once __DIR__ . '/../helpers.php';

$db = pw_db();
$rows = $db->query(
    'SELECT n.id, n.slug, n.title, n.body, n.author_type, n.published_at, u.display_name AS author_display_name
     FROM news_posts n
     LEFT JOIN users u ON u.id = n.author_user_id
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
    return [
        'slug' => $row['slug'],
        'title' => $row['title'],
        'body' => $row['body'],
        'author_type' => $row['author_type'],
        'author_display_name' => $row['author_display_name'],
        'published_at' => $row['published_at'],
        'tags' => $tagsByPost[(int)$row['id']] ?? [],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $entries]);
