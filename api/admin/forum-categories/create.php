<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/forum-categories-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.edit');

$input = pw_input();
pw_require_csrf($input);

$data = pw_validate_forum_category_input($input);

$db = pw_db();
$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM forum_categories')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare('INSERT INTO forum_categories (name, sort_order) VALUES (?, ?)');
$stmt->execute([$data['name'], $sortOrder]);
$categoryId = (int)$db->lastInsertId();

pw_log_admin_activity(
    'forum_category_created',
    'Added forum category "' . $data['name'] . '".',
    $adminUser
);

pw_json(['ok' => true, 'id' => $categoryId]);
