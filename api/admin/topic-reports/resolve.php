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
$stmt = $db->prepare("SELECT id, status, target_type, target_id, reporter_user_id FROM content_reports WHERE id = ?");
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

// Notify the original reporter, anonymously (no actor_user_id) -- the
// reporter shouldn't need to know which specific moderator handled it,
// just that it was resolved and what came of it.
$topicId = null;
$commentId = null;
if ($report['target_type'] === 'topic') {
    $topicId = (int)$report['target_id'];
} else {
    $commentId = (int)$report['target_id'];
    $topicStmt = $db->prepare('SELECT topic_id FROM comments WHERE id = ?');
    $topicStmt->execute([$commentId]);
    $commentRow = $topicStmt->fetch();
    if ($commentRow) {
        $topicId = (int)$commentRow['topic_id'];
    }
}
pw_notify((int)$report['reporter_user_id'], 'report_resolved', null, $topicId, $commentId, $id, $resolution);

pw_json(['ok' => true]);
