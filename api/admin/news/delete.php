<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('news.delete');
$input = pw_input();
pw_require_csrf($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing news post id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT title FROM news_posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    pw_error('That news post no longer exists.', 404);
}
$db->prepare('DELETE FROM news_posts WHERE id = ?')->execute([$id]);

pw_log_admin_activity('news_post_deleted', 'Deleted news post "' . $post['title'] . '".', $adminUser);
pw_json(['ok' => true]);
