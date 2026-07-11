<?php
/**
 * Accepts the full ordered list of overlord ids and rewrites sort_order 1..N.
 * Mirrors api/admin/worlds/reorder.php exactly.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('overlords.edit');

$input = pw_input();
pw_require_csrf($input);

$ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];
if (empty($ids)) {
    pw_error('Missing overlord order.');
}

$db = pw_db();
$existingIds = array_column($db->query('SELECT id FROM overlords')->fetchAll(), 'id');
$existingIds = array_map('intval', $existingIds);

if (count(array_diff($existingIds, $ids)) > 0 || count(array_diff($ids, $existingIds)) > 0) {
    pw_error('Overlord order is out of date. Reload and try again.', 409);
}

$db->beginTransaction();
$stmt = $db->prepare('UPDATE overlords SET sort_order = ? WHERE id = ?');
foreach ($ids as $i => $id) {
    $stmt->execute([$i + 1, $id]);
}
$db->commit();

pw_log_admin_activity('overlord_reordered', 'Reordered overlords.', $adminUser);

pw_json(['ok' => true]);
