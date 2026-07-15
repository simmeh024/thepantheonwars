<?php
/** Reusable tag catalogue for News Management autocomplete and filtering. */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('news.view');
$db = pw_db();
$tags = $db->query(
    'SELECT t.slug, t.label, COUNT(npt.news_post_id) AS post_count
     FROM news_tags t
     LEFT JOIN news_post_tags npt ON npt.news_tag_id = t.id
     GROUP BY t.id, t.slug, t.label
     ORDER BY t.label ASC'
)->fetchAll();

pw_json(['ok' => true, 'tags' => array_map(function ($tag) {
    return ['slug' => $tag['slug'], 'label' => $tag['label'], 'post_count' => (int)$tag['post_count']];
}, $tags)]);
