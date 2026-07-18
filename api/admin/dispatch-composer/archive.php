<?php
/** Archives a Composer draft/ready post, or a published one (its News post is untouched). */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.archive');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing Composer post id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, title, status FROM dispatch_composer_posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    pw_error('That Composer draft no longer exists.', 404);
}
if ($post['status'] === 'archived') {
    pw_error('That Composer draft is already archived.');
}

$db->prepare('UPDATE dispatch_composer_posts SET status = ? WHERE id = ?')->execute(['archived', $id]);

pw_log_admin_activity(
    'dispatch_composer.archived',
    'Archived Composer ' . ($post['status'] === 'published' ? 'article' : 'draft') . ' "' . ($post['title'] !== '' ? $post['title'] : '(untitled)') . '".',
    $adminUser
);

pw_json(['ok' => true]);
