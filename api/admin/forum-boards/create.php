<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/forum-boards-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.edit');

$input = pw_input();
pw_require_csrf($input);

$data = pw_validate_forum_board_input($input, true);

$db = pw_db();

$dupStmt = $db->prepare('SELECT id FROM forum_boards WHERE slug = ?');
$dupStmt->execute([$data['slug']]);
if ($dupStmt->fetch()) {
    pw_error('A board with that slug already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM forum_boards')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO forum_boards (slug, name, description, icon_key, is_public, sort_order)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $data['slug'], $data['name'], $data['description'], $data['icon_key'],
    $data['is_public'], $sortOrder,
]);
$boardId = (int)$db->lastInsertId();

if (!$data['is_public']) {
    pw_set_forum_board_roles($db, $boardId, $data['role_slugs']);
}

pw_log_admin_activity(
    'forum_board_created',
    'Added forum board "' . $data['name'] . '" (' . $data['slug'] . ').',
    $adminUser
);

pw_json(['ok' => true, 'id' => $boardId]);
