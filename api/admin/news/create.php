<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/news-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('news.edit');
$input = pw_input();
pw_require_csrf($input);
$data = pw_news_input($input);
$db = pw_db();

$slug = pw_news_unique_slug($db, $data['title']);
$authorUserId = $data['author_type'] === 'member' ? (int)$adminUser['id'] : null;
try {
    $db->beginTransaction();
    $stmt = $db->prepare(
        'INSERT INTO news_posts (slug, title, body, author_type, author_user_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$slug, $data['title'], $data['body'], $data['author_type'], $authorUserId]);
    $id = (int)$db->lastInsertId();
    pw_news_sync_tags($db, $id, $data['tags']);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_admin_activity(
    'news_post_created',
    'Published news post "' . $data['title'] . '" as ' . ($data['author_type'] === 'bh4' ? 'BH-4' : 'their member profile') . (empty($data['tags']) ? '.' : ' with ' . count($data['tags']) . ' tag(s).'),
    $adminUser
);

pw_json(['ok' => true, 'id' => $id, 'slug' => $slug]);
