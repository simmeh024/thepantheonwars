<?php
/**
 * Warnings admin queue (Community > Warnings) and the source data behind
 * the Member edit modal's "Warnings" block and the public-site Warn icon's
 * view section. Optional ?user_id= narrows to one member's history (used by
 * both of those deep-link callers); ?status=/?severity= narrow further.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('warnings.view');
$db = pw_db();

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
if (!in_array($status, ['active', 'revoked', 'all'], true)) {
    $status = 'all';
}
$severity = isset($_GET['severity']) ? trim($_GET['severity']) : '';
if (!in_array($severity, ['minor', 'moderate', 'severe'], true)) {
    $severity = '';
}

$where = [];
$params = [];
if ($userId > 0) {
    $where[] = 'w.user_id = ?';
    $params[] = $userId;
}
if ($status !== 'all') {
    $where[] = 'w.status = ?';
    $params[] = $status;
}
if ($severity !== '') {
    $where[] = 'w.severity = ?';
    $params[] = $severity;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    "SELECT w.id, w.user_id, w.reason, w.severity, w.source_type, w.source_id, w.status,
            w.issued_by_user_id, w.issued_by_username, w.revoked_by_username, w.revoke_reason,
            w.revoked_at, w.mute_minutes, w.created_at,
            u.username, u.display_name, u.role, r.color AS role_color
     FROM member_warnings w
     JOIN users u ON u.id = w.user_id
     LEFT JOIN roles r ON r.slug = u.role
     $whereSql
     ORDER BY w.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);

$out = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'user' => [
            'id' => (int)$row['user_id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'role_color' => $row['role_color'] ?: '#c7ccd6',
        ],
        'reason' => $row['reason'],
        'severity' => $row['severity'],
        'source_type' => $row['source_type'],
        'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
        'status' => $row['status'],
        'issued_by_username' => $row['issued_by_username'],
        'revoked_by_username' => $row['revoked_by_username'],
        'revoke_reason' => $row['revoke_reason'],
        'revoked_at' => $row['revoked_at'],
        'mute_minutes' => $row['mute_minutes'] !== null ? (int)$row['mute_minutes'] : null,
        'created_at' => $row['created_at'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'warnings' => $out]);
