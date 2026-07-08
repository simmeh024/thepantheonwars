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
    pw_error('Enter a reason before deleting this content.');
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

if ($report['target_type'] === 'topic') {
    $stmt = $db->prepare('SELECT id, title, is_deleted FROM topics WHERE id = ?');
    $stmt->execute([$report['target_id']]);
    $topic = $stmt->fetch();
    if (!$topic) {
        pw_error('That topic no longer exists.', 404);
    }
    if (!$topic['is_deleted']) {
        $stmt = $db->prepare('UPDATE topics SET is_deleted = 1 WHERE id = ?');
        $stmt->execute([$report['target_id']]);
        // Cascade, same as the public delete endpoint: replies of a deleted
        // topic shouldn't keep counting toward post counts / leaderboards.
        $stmt = $db->prepare('UPDATE comments SET is_deleted = 1 WHERE topic_id = ?');
        $stmt->execute([$report['target_id']]);
    }
    pw_log_admin_activity(
        'report_content_deleted',
        'Deleted the topic "' . $topic['title'] . '" from a report: ' . $reason,
        $user
    );
} else {
    $stmt = $db->prepare('SELECT id, body, is_deleted FROM comments WHERE id = ?');
    $stmt->execute([$report['target_id']]);
    $comment = $stmt->fetch();
    if (!$comment) {
        pw_error('That reply no longer exists.', 404);
    }
    if (!$comment['is_deleted']) {
        $stmt = $db->prepare('UPDATE comments SET is_deleted = 1 WHERE id = ?');
        $stmt->execute([$report['target_id']]);
    }
    $snippet = mb_substr(trim($comment['body']), 0, 60);
    pw_log_admin_activity(
        'report_content_deleted',
        'Deleted a reply ("' . $snippet . (mb_strlen(trim($comment['body'])) > 60 ? '...' : '') . '") from a report: ' . $reason,
        $user
    );
}

pw_json(['ok' => true]);
