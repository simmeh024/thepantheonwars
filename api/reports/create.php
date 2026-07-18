<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$targetType = isset($input['target_type']) ? trim((string)$input['target_type']) : '';
$targetId = isset($input['target_id']) ? (int)$input['target_id'] : 0;
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
$category = isset($input['category']) ? trim((string)$input['category']) : 'other';

if (!in_array($targetType, ['topic', 'comment', 'news_comment'], true)) {
    pw_error('Unknown report target.');
}
if ($targetId <= 0) {
    pw_error('Missing target id.');
}
if ($reason === '') {
    pw_error('Tell us why you\'re reporting this.');
}
if (mb_strlen($reason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}
if (!in_array($category, ['spam', 'harassment', 'off_topic', 'spoiler_untagged', 'other'], true)) {
    $category = 'other';
}

$db = pw_db();

if ($targetType === 'topic') {
    $stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
} elseif ($targetType === 'comment') {
    $stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND is_deleted = 0');
} else {
    $stmt = $db->prepare('SELECT id FROM news_comments WHERE id = ?');
}
$stmt->execute([$targetId]);
if (!$stmt->fetch()) {
    pw_error('That ' . $targetType . ' no longer exists.', 404);
}

// Don't let the same person pile up duplicate open reports on the same
// target -- if they already have one pending, just let them know instead
// of creating a second identical row for moderators to review.
$dupStmt = $db->prepare(
    "SELECT id FROM content_reports
     WHERE target_type = ? AND target_id = ? AND reporter_user_id = ? AND status = 'open'"
);
$dupStmt->execute([$targetType, $targetId, $user['id']]);
if ($dupStmt->fetch()) {
    pw_error('You\'ve already reported this, and it\'s still awaiting review.');
}

$stmt = $db->prepare(
    'INSERT INTO content_reports (target_type, target_id, reporter_user_id, reason, category) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$targetType, $targetId, $user['id'], $reason, $category]);

pw_json(['ok' => true]);
