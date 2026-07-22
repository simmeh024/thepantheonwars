<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/timeline-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('timeline.edit');

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing timeline event id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT * FROM timeline_events WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();
if (!$existing) {
    pw_error('Timeline event not found.', 404);
}

// Slug is immutable once created -- same convention as Known Figures/Overlord/
// World/Forum Control's own slug fields.
$data = pw_validate_timeline_input($input);
$data['required_level_id'] = pw_validate_timeline_level($db, $data['required_level_id']);

$stmt = $db->prepare(
    'UPDATE timeline_events SET
        title = ?, era_label = ?, date_label = ?, summary = ?, body = ?,
        image_url = ?, accent_color = ?, required_level_id = ?, is_published = ?
     WHERE id = ?'
);
$stmt->execute([
    $data['title'], $data['era_label'], $data['date_label'], $data['summary'], $data['body'],
    $data['image_url'], $data['accent_color'], $data['required_level_id'], $data['is_published'],
    $id,
]);

// Changing the gate changes who can read the event, so it is worth its own
// audit line rather than being folded into the generic update entry.
$previousLevel = $existing['required_level_id'] !== null ? (int)$existing['required_level_id'] : null;
if ($previousLevel !== $data['required_level_id']) {
    pw_log_admin_activity(
        'timeline_event_gate_changed',
        'Changed the reputation gate on timeline event "' . $data['title'] . '".',
        $adminUser
    );
} else {
    pw_log_admin_activity('timeline_event_updated', 'Updated timeline event "' . $data['title'] . '".', $adminUser);
}

pw_json(['ok' => true]);
