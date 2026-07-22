<?php
/**
 * Issues a new warning against a member -- reachable from the Warnings
 * queue's own "Issue Warning" button, the Member edit modal, and the small
 * per-post Warn icon on forum posts/replies and News comments (which supply
 * source_type/source_id so the warning records which post triggered it).
 * Optionally accompanies the warning with a fixed-duration mute.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_permission('warnings.manage');
$input = pw_input();
pw_require_csrf($input);

$targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
$severity = isset($input['severity']) ? trim((string)$input['severity']) : 'minor';
$sourceType = isset($input['source_type']) ? trim((string)$input['source_type']) : 'manual';
$sourceId = isset($input['source_id']) ? (int)$input['source_id'] : null;
// One of the five fixed durations offered in the Issue Warning UI, or absent
// entirely for a warning with no accompanying mute.
$muteMinutes = isset($input['mute_minutes']) && $input['mute_minutes'] !== '' ? (int)$input['mute_minutes'] : null;

if ($targetUserId <= 0) {
    pw_error('Choose a member to warn.');
}
if ($targetUserId === (int)$user['id']) {
    pw_error('You cannot warn yourself.');
}
if ($reason === '') {
    pw_error('Enter a reason for this warning.');
}
if (mb_strlen($reason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}
if (!in_array($severity, ['minor', 'moderate', 'severe'], true)) {
    pw_error('Unknown severity.');
}
if (!in_array($sourceType, ['manual', 'topic', 'comment', 'news_comment'], true)) {
    pw_error('Unknown source type.');
}
if ($sourceType === 'manual') {
    $sourceId = null;
}
if ($muteMinutes !== null && !in_array($muteMinutes, [60, 720, 1440, 4320, 10080], true)) {
    pw_error('Unknown mute duration.');
}

$db = pw_db();
$targetStmt = $db->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
$targetStmt->execute([$targetUserId]);
$target = $targetStmt->fetch();
if (!$target) {
    pw_error('Member not found.', 404);
}

$stmt = $db->prepare(
    'INSERT INTO member_warnings
        (user_id, reason, severity, source_type, source_id, issued_by_user_id, issued_by_username, mute_minutes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$targetUserId, $reason, $severity, $sourceType, $sourceId, $user['id'], $user['username'], $muteMinutes]);
$warningId = (int)$db->lastInsertId();

pw_log_admin_activity(
    'warning_issued',
    'Issued a ' . $severity . ' warning to ' . $target['display_name'] . ': ' . $reason,
    $user
);

$muteUntil = null;
if ($muteMinutes !== null) {
    $muteStmt = $db->prepare('UPDATE users SET muted_until = NOW() + INTERVAL ? MINUTE, mute_reason = ? WHERE id = ?');
    $muteStmt->execute([$muteMinutes, $reason, $targetUserId]);
    $untilStmt = $db->prepare('SELECT muted_until FROM users WHERE id = ?');
    $untilStmt->execute([$targetUserId]);
    $muteUntil = $untilStmt->fetch()['muted_until'];
    pw_log_admin_activity(
        'member_muted',
        'Muted ' . $target['display_name'] . ' until ' . $muteUntil . ' UTC.',
        $user
    );
}

// Reason + severity travel in the notification excerpt; the issuer's
// identity deliberately does not, matching the anonymous topic-report
// resolution notification precedent -- staff-only visibility of who issued
// a given warning.
$excerpt = ucfirst($severity) . ' warning: ' . $reason;
if ($muteUntil !== null) {
    $excerpt .= ' You have also been muted until ' . date('M j, Y g:i A', strtotime($muteUntil)) . ' UTC.';
}
pw_notify($targetUserId, 'warning_issued', null, null, null, null, $excerpt);

pw_json(['ok' => true, 'id' => $warningId, 'muted_until' => $muteUntil]);
