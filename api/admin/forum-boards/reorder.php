<?php
/**
 * Accepts the full ordered list of board ids and rewrites sort_order 1..N.
 * The admin UI only exposes Move Up/Move Down buttons (no drag-and-drop
 * precedent exists yet in this codebase), but the API takes a full order
 * so a swap is just "send the two ids in their new order" -- no special
 * swap-only endpoint needed.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('forum_boards.edit');

$input = pw_input();
pw_require_csrf($input);

$ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];
if (empty($ids)) {
    pw_error('Missing board order.');
}

$db = pw_db();
$existingIds = array_column($db->query('SELECT id FROM forum_boards')->fetchAll(), 'id');
$existingIds = array_map('intval', $existingIds);

if (count(array_diff($existingIds, $ids)) > 0 || count(array_diff($ids, $existingIds)) > 0) {
    pw_error('Board order is out of date. Reload and try again.', 409);
}

$db->beginTransaction();
$stmt = $db->prepare('UPDATE forum_boards SET sort_order = ? WHERE id = ?');
foreach ($ids as $i => $id) {
    $stmt->execute([$i + 1, $id]);
}
$db->commit();

pw_log_admin_activity('forum_board_reordered', 'Reordered forum boards.', $adminUser);

pw_json(['ok' => true]);
