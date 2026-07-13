<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$admin = pw_require_permission('privacy_requests.manage');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$status = isset($input['status']) ? trim((string)$input['status']) : '';
$resolution = isset($input['staff_resolution']) ? trim((string)$input['staff_resolution']) : '';
$allowed = ['submitted', 'identity_check', 'in_progress', 'fulfilled', 'partially_fulfilled', 'rejected', 'withdrawn'];
$closed = ['fulfilled', 'partially_fulfilled', 'rejected', 'withdrawn'];

if ($id <= 0 || !in_array($status, $allowed, true)) {
    pw_error('Choose a valid privacy request and status.');
}
if (mb_strlen($resolution) > 2000) {
    pw_error('Please keep the staff resolution to 2000 characters or fewer.');
}
if (in_array($status, $closed, true) && $resolution === '') {
    pw_error('Record the outcome before closing a privacy request.');
}

try {
    $db = pw_db();
    $check = $db->prepare('SELECT id FROM privacy_requests WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) {
        pw_error('Privacy request not found.', 404);
    }
    $stmt = $db->prepare(
        'UPDATE privacy_requests
         SET status = ?, staff_resolution = ?, handled_by = ?,
             handled_at = CASE WHEN ? IN (\'fulfilled\', \'partially_fulfilled\', \'rejected\', \'withdrawn\') THEN NOW() ELSE NULL END
         WHERE id = ?'
    );
    $stmt->execute([$status, $resolution !== '' ? $resolution : null, $admin['id'], $status, $id]);
    // Never include request text, email addresses or a resolution in the audit log.
    pw_log_admin_activity('privacy_request_updated', 'Updated privacy request #' . $id . ' to ' . $status . '.', $admin);
    pw_json(['ok' => true]);
} catch (PDOException $e) {
    pw_error('Privacy requests are not available yet. Run migration_privacy_requests.sql.', 503);
}
