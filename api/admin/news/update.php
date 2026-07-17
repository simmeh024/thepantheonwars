<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/news-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('news.edit');
$input = pw_input();
pw_require_csrf($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing news post id.');
}
$data = pw_news_input($input);
$db = pw_db();
$existingStmt = $db->prepare('SELECT id, title FROM news_posts WHERE id = ?');
$existingStmt->execute([$id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    pw_error('That news post no longer exists.', 404);
}

$authorUserId = $data['author_type'] === 'member' ? (int)$adminUser['id'] : null;
try {
    $db->beginTransaction();
    $stmt = $db->prepare(
        'UPDATE news_posts SET title = ?, body = ?, header_image_url = ?, author_type = ?, author_user_id = ?, comments_enabled = ? WHERE id = ?'
    );
    $stmt->execute([$data['title'], $data['body'], $data['header_image_url'], $data['author_type'], $authorUserId, $data['comments_enabled'] ? 1 : 0, $id]);
    pw_news_sync_tags($db, $id, $data['tags']);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_admin_activity(
    'news_post_updated',
    'Updated news post "' . $data['title'] . '" (' . ($data['author_type'] === 'bh4' ? 'BH-4' : 'member profile') . ').',
    $adminUser
);

pw_json(['ok' => true]);
