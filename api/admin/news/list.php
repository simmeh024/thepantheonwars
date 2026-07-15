<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('news.view');
$db = pw_db();

$rows = $db->query(
    'SELECT n.id, n.slug, n.title, n.body, n.author_type, n.author_user_id, n.published_at, n.created_at, n.updated_at,
            u.display_name AS author_display_name
     FROM news_posts n
     LEFT JOIN users u ON u.id = n.author_user_id
     ORDER BY n.published_at DESC, n.id DESC'
)->fetchAll();

$entries = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'body' => $row['body'],
        'author_type' => $row['author_type'],
        'author_user_id' => $row['author_user_id'] !== null ? (int)$row['author_user_id'] : null,
        'author_display_name' => $row['author_display_name'],
        'published_at' => $row['published_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}, $rows);

pw_json(['ok' => true, 'entries' => $entries, 'total' => count($entries)]);
