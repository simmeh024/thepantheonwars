<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/forum-boards-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.edit');

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

// Slug is immutable once created -- topics.board references it by string,
// so this is never accepted from the client, regardless of what a request
// sends (the UI disables the field, but the server must not trust that).
$data = pw_validate_forum_board_input($input, false);

$stmt = $db->prepare(
    'UPDATE forum_boards SET name = ?, description = ?, icon_key = ?, accent_color = ?, is_public = ? WHERE id = ?'
);
$stmt->execute([$data['name'], $data['description'], $data['icon_key'], $data['accent_color'], $data['is_public'], $id]);

pw_set_forum_board_roles($db, $id, $data['is_public'] ? [] : $data['role_slugs']);

pw_log_admin_activity(
    'forum_board_updated',
    'Updated forum board "' . $data['name'] . '" (' . $existing['slug'] . ').',
    $adminUser
);

pw_json(['ok' => true]);
