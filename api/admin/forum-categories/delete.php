<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.delete');

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

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM forum_boards WHERE category_id = ?');
$countStmt->execute([$id]);
$boardCount = (int)$countStmt->fetch()['cnt'];
if ($boardCount > 0) {
    pw_error('Move this category\'s ' . $boardCount . ' board(s) elsewhere before deleting it.', 400);
}

$stmt = $db->prepare('DELETE FROM forum_categories WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity(
    'forum_category_deleted',
    'Deleted forum category "' . $existing['name'] . '".',
    $adminUser
);

pw_json(['ok' => true]);
