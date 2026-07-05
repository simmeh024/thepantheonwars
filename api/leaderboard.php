<?php
require_once __DIR__ . '/helpers.php';

// Public read — no login required, matches the forum's read-without-account design.
$db = pw_db();
$stmt = $db->prepare(
    'SELECT u.id, u.display_name, u.role, COUNT(c.id) AS post_count
     FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.is_deleted = 0
     GROUP BY u.id, u.display_name, u.role
     ORDER BY post_count DESC, u.display_name ASC
     LIMIT 10'
);
$stmt->execute();
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'display_name' => $r['display_name'],
        'role' => $r['role'],
        'post_count' => (int)$r['post_count'],
    ];
}, $rows);

pw_json(['ok' => true, 'leaders' => $out]);
