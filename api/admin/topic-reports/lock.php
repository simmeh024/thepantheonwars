<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_mod_or_admin();
$input = pw_input();
pw_require_csrf($input);

$reportId = isset($input['report_id']) ? (int)$input['report_id'] : 0;
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';

if ($reportId <= 0) {
    pw_error('Missing report id.');
}
if ($reason === '') {
    pw_error('Enter a reason before locking/unlocking this topic.');
}
if (mb_strlen($reason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, target_type, target_id FROM content_reports WHERE id = ?');
$stmt->execute([$reportId]);
$report = $stmt->fetch();
if (!$report) {
    pw_error('Report not found.', 404);
}

// A comment report has no lock state of its own -- locking applies to the
// whole topic it belongs to.
if ($report['target_type'] === 'topic') {
    $topicId = (int)$report['target_id'];
} else {
    $stmt = $db->prepare('SELECT topic_id FROM comments WHERE id = ?');
    $stmt->execute([$report['target_id']]);
    $comment = $stmt->fetch();
    if (!$comment) {
        pw_error('That reply no longer exists.', 404);
    }
    $topicId = (int)$comment['topic_id'];
}

$stmt = $db->prepare('SELECT id, title, is_locked FROM topics WHERE id = ?');
$stmt->execute([$topicId]);
$topic = $stmt->fetch();
if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$newState = $topic['is_locked'] ? 0 : 1;
$stmt = $db->prepare('UPDATE topics SET is_locked = ?, locked_at = ? WHERE id = ?');
$stmt->execute([$newState, $newState ? date('Y-m-d H:i:s') : null, $topicId]);

pw_log_admin_activity(
    $newState ? 'topic_locked' : 'topic_unlocked',
    ($newState ? 'Locked' : 'Unlocked') . ' the topic "' . $topic['title'] . '" from a report: ' . $reason,
    $user
);

pw_json(['ok' => true, 'isLocked' => (bool)$newState]);
