<?php
require_once __DIR__ . '/helpers.php';

// Public community rankings for both forum activity and all-time reputation.
$db = pw_db();
$metric = isset($_GET['metric']) && $_GET['metric'] === 'reputation' ? 'reputation' : 'posts';
$having = $metric === 'reputation' ? '(u.reputation > 0 OR post_count > 0)' : 'post_count > 0';
$order = $metric === 'reputation' ? 'u.reputation DESC, post_count DESC, u.display_name ASC' : 'post_count DESC, u.display_name ASC';
$stmt = $db->prepare(
    'SELECT u.id, u.display_name, u.role, r.color AS role_color, u.reputation,
       (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id AND c.is_deleted = 0) +
       (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id AND t.is_deleted = 0) AS post_count
     FROM users u LEFT JOIN roles r ON r.slug = u.role
     HAVING ' . $having . ' ORDER BY ' . $order . ' LIMIT 10'
);
$stmt->execute();
$out = array_map(function ($r) {
    return ['id' => (int)$r['id'], 'display_name' => $r['display_name'], 'role' => $r['role'],
        'role_color' => $r['role_color'] ?: '#c7ccd6', 'reputation' => (int)$r['reputation'], 'post_count' => (int)$r['post_count']];
}, $stmt->fetchAll());
pw_json(['ok' => true, 'metric' => $metric, 'leaders' => $out]);
