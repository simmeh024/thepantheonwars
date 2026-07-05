<?php
require_once __DIR__ . '/helpers.php';

// Public read — no login required, matches the forum's read-without-account design.
// Counts topics started + replies posted, combined, as the activity measure.
$db = pw_db();
$stmt = $db->prepare(
    'SELECT u.id, u.display_name, u.role,
       (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id AND c.is_deleted = 0) +
       (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id AND t.is_deleted = 0) AS post_count
     FROM users u
     HAVING post_count > 0
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
