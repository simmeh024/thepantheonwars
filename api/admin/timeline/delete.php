<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('timeline.delete');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing timeline event id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT title FROM timeline_events WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Timeline event not found.', 404);
}

$db->beginTransaction();
// Discovery rows are not cascaded by a foreign key (user_lore_discoveries is
// deliberately generic across entity types and only constrains user_id), so
// clear this event's rows explicitly. Leaving them would let a recycled
// AUTO_INCREMENT id mark a future event as already discovered, silently
// denying that member the award.
$discoveryStmt = $db->prepare("DELETE FROM user_lore_discoveries WHERE entity_type = 'timeline_event' AND entity_id = ?");
$discoveryStmt->execute([$id]);

$stmt = $db->prepare('DELETE FROM timeline_events WHERE id = ?');
$stmt->execute([$id]);
$db->commit();

pw_log_admin_activity('timeline_event_deleted', 'Deleted timeline event "' . $existing['title'] . '".', $adminUser);

pw_json(['ok' => true]);
