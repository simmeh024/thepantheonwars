<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/timeline-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('timeline.edit');

$input = pw_input();
pw_require_csrf($input);

$slug = pw_validate_timeline_slug($input);
$data = pw_validate_timeline_input($input);

$db = pw_db();
$data['required_level_id'] = pw_validate_timeline_level($db, $data['required_level_id']);

$dupStmt = $db->prepare('SELECT id FROM timeline_events WHERE slug = ?');
$dupStmt->execute([$slug]);
if ($dupStmt->fetch()) {
    pw_error('A timeline event with that slug already exists.', 409);
}

$maxSort = $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM timeline_events')->fetch();
$sortOrder = (int)$maxSort['m'] + 1;

$stmt = $db->prepare(
    'INSERT INTO timeline_events (
        slug, title, era_label, date_label, summary, body,
        image_url, accent_color, required_level_id, is_published, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $slug, $data['title'], $data['era_label'], $data['date_label'], $data['summary'], $data['body'],
    $data['image_url'], $data['accent_color'], $data['required_level_id'], $data['is_published'], $sortOrder,
]);
$eventId = (int)$db->lastInsertId();

pw_log_admin_activity('timeline_event_created', 'Added timeline event "' . $data['title'] . '".', $adminUser);

pw_json(['ok' => true, 'id' => $eventId]);
