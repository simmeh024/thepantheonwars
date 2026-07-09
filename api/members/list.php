<?php
require_once __DIR__ . '/../helpers.php';

// Public read, matches the forum's read-without-account design.
// "Online" is derived from last_active_at, which is refreshed on every
// page load (see session-check.php) for logged-in visitors.
$db = pw_db();
$stmt = $db->prepare(
    "SELECT u.id, u.display_name, u.role, u.last_login_at, u.last_active_at, r.color AS role_color,
       (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id AND c.is_deleted = 0) +
       (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id AND t.is_deleted = 0) AS post_count,
       (u.last_active_at IS NOT NULL AND u.last_active_at >= (NOW() - INTERVAL 5 MINUTE)) AS is_online
     FROM users u
     LEFT JOIN roles r ON r.slug = u.role
     ORDER BY is_online DESC,
       FIELD(u.role, 'admin', 'moderator', 'member'),
       u.display_name ASC
     LIMIT 500"
);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'role_color' => $r['role_color'] ?: '#c7ccd6',
        'post_count' => (int)$r['post_count'],
        'last_login_at' => $r['last_login_at'],
        'last_active_at' => $r['last_active_at'],
        'is_online' => (bool)$r['is_online'],
    ];
}, $rows);

pw_json(['ok' => true, 'members' => $out]);
