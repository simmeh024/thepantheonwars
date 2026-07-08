<?php
/**
 * Re-opens a resolved report so it goes back into the Open queue on the
 * Topic Reports page. Clears the old resolution/resolver/resolved_at fields
 * (a fresh resolution will be recorded whenever it's closed again) and logs
 * the reopen -- with the reason the moderator gave -- to admin_activity_log.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_mod_or_admin();
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';

if ($id <= 0) {
    pw_error('Missing report id.');
}
if ($reason === '') {
    pw_error('Enter a reason before reopening this report.');
}
if (mb_strlen($reason) > 1000) {
    pw_error('That reason is too long (1000 characters max).');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, status, target_type, target_id FROM content_reports WHERE id = ?');
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    pw_error('Report not found.', 404);
}
if ($report['status'] === 'open') {
    pw_error('This report is already open.');
}

$stmt = $db->prepare(
    "UPDATE content_reports SET status = 'open', resolution = NULL, resolved_by = NULL, resolved_at = NULL WHERE id = ?"
);
$stmt->execute([$id]);

pw_log_admin_activity(
    'report_reopened',
    'Reopened a report on ' . $report['target_type'] . ' #' . $report['target_id'] . ': ' . $reason,
    $user
);

pw_json(['ok' => true]);
