<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_permission('topic_reports.manage');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$resolution = isset($input['resolution']) ? trim((string)$input['resolution']) : '';

if ($id <= 0) {
    pw_error('Missing report id.');
}
if ($resolution === '') {
    pw_error('Enter a resolution before closing this report.');
}
if (mb_strlen($resolution) > 1000) {
    pw_error('That resolution is too long (1000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare("SELECT id, status, target_type, target_id FROM content_reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    pw_error('Report not found.', 404);
}
if ($report['status'] === 'resolved') {
    pw_error('This report is already closed.');
}

$stmt = $db->prepare(
    "UPDATE content_reports SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?"
);
$stmt->execute([$resolution, $user['id'], $id]);

pw_log_admin_activity(
    'report_resolved',
    'Closed a report on ' . $report['target_type'] . ' #' . $report['target_id'] . ': ' . $resolution,
    $user
);

pw_json(['ok' => true]);
