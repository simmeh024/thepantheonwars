<?php
require_once __DIR__ . '/direct-message-helpers.php';

$user = pw_require_login();
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($query === '') {
    pw_json(['ok' => true, 'members' => []]);
}
if (mb_strlen($query) > 60) {
    $query = mb_substr($query, 0, 60);
}

// Search display names and member handles, but reveal only the established
// public profile information in the result list. Regular members see active,
// non-blocked recipients; staff retain the approved send override.
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
$staff = pw_is_staff_messenger($user);
$sql = 'SELECT u.id, u.display_name, u.role, u.presence_status, u.last_active_at, u.created_at, r.color AS role_color
        FROM users u
        LEFT JOIN roles r ON r.slug = u.role
        WHERE u.id != ?
          AND (u.display_name LIKE ? OR u.username LIKE ?)';
$params = [$user['id'], $like, $like];
if (!$staff) {
    $sql .= ' AND (u.banned_at IS NULL OR (u.banned_until IS NOT NULL AND u.banned_until <= NOW()))
              AND NOT EXISTS (
                SELECT 1 FROM user_blocks ub
                WHERE (ub.blocker_user_id = ? AND ub.blocked_user_id = u.id)
                   OR (ub.blocker_user_id = u.id AND ub.blocked_user_id = ?)
              )';
    $params[] = $user['id'];
    $params[] = $user['id'];
}
$sql .= ' ORDER BY u.display_name ASC, u.id ASC LIMIT 8';
$stmt = pw_db()->prepare($sql);
$stmt->execute($params);

$members = array_map(function ($row) {
    return [
        'id' => (int)$row['id'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'role_color' => $row['role_color'] ?: '#c7ccd6',
        'presence_status' => pw_public_presence_status($row['presence_status'], $row['last_active_at']),
        'created_at' => $row['created_at'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'members' => $members]);
