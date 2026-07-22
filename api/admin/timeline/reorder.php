<?php
/**
 * Accepts the full ordered list of timeline event ids and rewrites sort_order
 * 1..N. Mirrors api/admin/known-figures/reorder.php exactly.
 *
 * Ordering matters more here than on other flat entities: date_label is free
 * in-world text that cannot be sorted, so sort_order alone decides the order
 * events appear along the public bar.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('timeline.edit');

$input = pw_input();
pw_require_csrf($input);

$ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];
if (empty($ids)) {
    pw_error('Missing timeline order.');
}

$db = pw_db();
$existingIds = array_column($db->query('SELECT id FROM timeline_events')->fetchAll(), 'id');
$existingIds = array_map('intval', $existingIds);

if (count(array_diff($existingIds, $ids)) > 0 || count(array_diff($ids, $existingIds)) > 0) {
    pw_error('Timeline order is out of date. Reload and try again.', 409);
}

$db->beginTransaction();
$stmt = $db->prepare('UPDATE timeline_events SET sort_order = ? WHERE id = ?');
foreach ($ids as $i => $id) {
    $stmt->execute([$i + 1, $id]);
}
$db->commit();

pw_log_admin_activity('timeline_event_reordered', 'Reordered the lore timeline.', $adminUser);

pw_json(['ok' => true]);
