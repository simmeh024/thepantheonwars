<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/forum-categories-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing category id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM forum_categories WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Category not found.', 404);
}

$data = pw_validate_forum_category_input($input);

$stmt = $db->prepare('UPDATE forum_categories SET name = ? WHERE id = ?');
$stmt->execute([$data['name'], $id]);

pw_log_admin_activity(
    'forum_category_updated',
    'Updated forum category "' . $data['name'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
