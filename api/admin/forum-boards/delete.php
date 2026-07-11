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
    pw_error('Missing board id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM forum_boards WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Board not found.', 404);
}

if ($existing['is_protected']) {
    pw_error('This board is protected and can\'t be deleted.', 400);
}

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM topics WHERE board = ? AND is_deleted = 0');
$countStmt->execute([$existing['slug']]);
$topicCount = (int)$countStmt->fetch()['cnt'];
if ($topicCount > 0) {
    pw_error('Move this board\'s ' . $topicCount . ' topic(s) elsewhere before deleting it.', 400);
}

// forum_board_roles rows cascade-delete automatically via the FK.
$stmt = $db->prepare('DELETE FROM forum_boards WHERE id = ?');
$stmt->execute([$id]);

pw_log_admin_activity(
    'forum_board_deleted',
    'Deleted forum board "' . $existing['name'] . '" (' . $existing['slug'] . ').',
    $adminUser
);

pw_json(['ok' => true]);
