<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('privacy_requests.view');

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'active';
$allowed = ['active', 'completed', 'all'];
if (!in_array($status, $allowed, true)) {
    $status = 'active';
}

$where = '';
if ($status === 'active') {
    $where = "WHERE pr.status IN ('submitted', 'identity_check', 'in_progress')";
} elseif ($status === 'completed') {
    $where = "WHERE pr.status IN ('fulfilled', 'partially_fulfilled', 'rejected', 'withdrawn')";
}

try {
    $rows = pw_db()->query(
        "SELECT pr.id, pr.requester_email, pr.request_type, pr.message, pr.status,
                pr.staff_resolution, pr.due_at, pr.created_at, pr.updated_at, pr.handled_at,
                u.username, u.display_name, h.username AS handler_username
         FROM privacy_requests pr
         LEFT JOIN users u ON u.id = pr.requester_user_id
         LEFT JOIN users h ON h.id = pr.handled_by
         $where
         ORDER BY FIELD(pr.status, 'submitted', 'identity_check', 'in_progress', 'partially_fulfilled', 'rejected', 'fulfilled', 'withdrawn'),
                  pr.due_at ASC, pr.created_at DESC"
    )->fetchAll();
} catch (PDOException $e) {
    pw_error('Privacy requests are not available yet. Run migration_privacy_requests.sql.', 503);
}

pw_json(['ok' => true, 'requests' => array_map(function ($row) {
    return [
        'id' => (int)$row['id'], 'requester_email' => $row['requester_email'],
        'request_type' => $row['request_type'], 'message' => $row['message'],
        'status' => $row['status'], 'staff_resolution' => $row['staff_resolution'],
        'due_at' => $row['due_at'], 'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'], 'handled_at' => $row['handled_at'],
        'requester_username' => $row['username'], 'requester_display_name' => $row['display_name'],
        'handler_username' => $row['handler_username'],
    ];
}, $rows)]);
